<?php
namespace common\modules\world\service;

use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Bounded queries; privacy filtering precedes counts, limits and aggregates. */
class WorldQuery
{
    private $db;
    private $policy;
    public function __construct(Connection $db, WorldAccessPolicy $policy) { $this->db = $db; $this->policy = $policy; }
    private function visible(): Query { return $this->policy->filter((new Query())->from(['n' => 'world_node'])); }
    public function node(int $id): array
    {
        $row = $this->visible()->andWhere(['n.id' => $id])->one($this->db);
        if (!$row) throw new GameError('NODE_NOT_FOUND', 'Объект не найден.', 404);
        return $this->present([$row])[0];
    }
    public function children(int $id, array $filter = []): array
    {
        $this->node($id);
        $page = $this->positive($filter['page'] ?? 1, 1000000); $size = $this->positive($filter['per-page'] ?? 20, 100);
        $q = $filter['q'] ?? ''; $type = $filter['type'] ?? '';
        if (!is_string($q) || mb_strlen($q, 'UTF-8') > 100 || !is_string($type) || ($type !== '' && !in_array($type, WorldTree::TYPES, true))) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        $query = $this->visible()->andWhere(['n.parent_id' => $id]);
        if ($q !== '') $query->andWhere(['like', 'n.name', trim($q)]);
        if ($type !== '') $query->andWhere(['n.node_type' => $type]);
        if (isset($filter['bbox'])) {
            if (!is_string($filter['bbox']) || !preg_match('/^-?\d+,-?\d+,-?\d+,-?\d+$/D', $filter['bbox'])) throw new GameError('INVALID_VIEWPORT', 'Некорректная область карты.', 422);
            $bbox = array_map('intval', explode(',', $filter['bbox']));
            if ($bbox[0] > $bbox[2] || $bbox[1] > $bbox[3] || max(array_map('abs', $bbox)) > 1000000) throw new GameError('INVALID_VIEWPORT', 'Некорректная область карты.', 422);
            $query->andWhere(['between', 'n.position_x', $bbox[0], $bbox[2]])->andWhere(['between', 'n.position_y', $bbox[1], $bbox[3]]);
        }
        $total = (int)(clone $query)->count('*', $this->db);
        $rows = $query->orderBy(['n.position' => SORT_ASC, 'n.id' => SORT_ASC])->offset(($page - 1) * $size)->limit($size)->all($this->db);
        return ['items' => $this->present($rows), '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / $size), 'currentPage' => $page, 'perPage' => $size]];
    }
    /** Map data is independent of list pagination and preserves absolute coordinates. */
    public function map(int $id): array
    {
        $node = $this->node($id);
        $rows = $this->visible()->andWhere(['n.parent_id' => $id])->orderBy(['n.id' => SORT_ASC])->all($this->db);
        $cells = (new Query())->select(['x', 'y', 'state'])->from('world_map_cell')->where(['parent_id' => $id])->orderBy(['x' => SORT_ASC, 'y' => SORT_ASC])->all($this->db);
        $garden = $node['type'] === 'PLOT' && (((array)$node['details'])['plot_kind'] ?? null) === 'garden';
        return ['node_id' => $id, 'items' => $this->present($rows), 'cells' => array_map(static function (array $cell): array {
            return ['x' => (int)$cell['x'], 'y' => (int)$cell['y'], 'state' => $cell['state']];
        }, $cells), 'can_expand' => $node['permissions']['manage'] && !$garden && !in_array($node['type'], ['ROOM', 'BED'], true)];
    }
    public function navigation(int $id): array
    {
        $node = $this->node($id);
        $rows = $this->visible()->innerJoin(['path' => 'world_node_closure'], '[[path.ancestor_id]]=[[n.id]]')->andWhere(['path.descendant_id' => $id])->orderBy(['path.distance' => SORT_DESC])->all($this->db);
        return ['node' => $node, 'breadcrumbs' => $this->present($rows), 'parent_id' => $node['parent_id'],
            'siblings' => $node['parent_id'] ? $this->children($node['parent_id']) : ['items' => [], '_meta' => ['totalCount' => 0, 'pageCount' => 0, 'currentPage' => 1, 'perPage' => 20]]];
    }
    public function statistics(int $id): array
    {
        $this->node($id);
        $rows = $this->visible()->innerJoin(['path' => 'world_node_closure'], '[[path.descendant_id]]=[[n.id]]')->andWhere(['path.ancestor_id' => $id])
            ->select(['type' => 'n.node_type', 'count' => new \yii\db\Expression('COUNT(*)')])->groupBy('n.node_type')->all($this->db);
        return array_map(static function (array $row): array { return ['type' => $row['type'], 'count' => (int)$row['count']]; }, $rows);
    }
    private function present(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_column($rows, 'id');
        $purchasedRooms = [];
        if ($this->db->schema->getTableSchema('world_premises_purchase')) {
            $purchasedRooms = (new Query())->select(['room_id' => 'p.room_id', 'building_name' => 'b.name'])->from(['p' => 'world_premises_purchase'])
                ->innerJoin(['b' => 'world_node'], '[[b.id]]=[[p.building_id]]')->where(['p.room_id' => $ids])->indexBy('room_id')->all($this->db);
        }
        $counts = $this->visible()->select(['parent_id' => 'n.parent_id', 'amount' => new \yii\db\Expression('COUNT(*)')])->andWhere(['n.parent_id' => $ids])->groupBy('n.parent_id')->indexBy('parent_id')->all($this->db);
        $descendants = $this->visible()->innerJoin(['path' => 'world_node_closure'], '[[path.descendant_id]]=[[n.id]]')
            ->select(['ancestor_id' => 'path.ancestor_id', 'amount' => new \yii\db\Expression('COUNT(*)')])
            ->andWhere(['path.ancestor_id' => $ids])->andWhere(['>', 'path.distance', 0])
            ->groupBy('path.ancestor_id')->indexBy('ancestor_id')->all($this->db);
        $populations = (new Query())->from(['path' => 'world_node_closure'])
            ->innerJoin(['n' => 'world_node'], '[[n.id]]=[[path.descendant_id]]')
            ->innerJoin(['settlement' => 'world_settlement'], '[[settlement.node_id]]=[[n.id]]')
            ->select(['ancestor_id' => 'path.ancestor_id', 'amount' => new \yii\db\Expression('SUM([[settlement.population]])')])
            ->where(['path.ancestor_id' => $ids])->andWhere(['<>', 'n.status', 'archived'])
            ->groupBy('path.ancestor_id')->indexBy('ancestor_id')->all($this->db);
        $details = [];
        foreach (array_unique(array_column($rows, 'node_type')) as $type) {
            if ($type === 'WORLD') continue;
            $details += (new Query())->from('world_' . strtolower($type))->where(['node_id' => $ids])->indexBy('node_id')->all($this->db);
        }
        $shelters = [];
        if ($this->db->schema->getTableSchema('world_shelter_deployment')) {
            $shelters = (new Query())->from('world_shelter_deployment')->where(['node_id' => $ids])->indexBy('node_id')->all($this->db);
            $units = (new Query())->from('craft_equipment_instance')->where(['id' => array_column($shelters, 'instance_id')])->all($this->db);
            $projected = [];
            foreach ((new \common\modules\craft\service\EquipmentExposure($this->db))->projectedBatch($units) as $unit) $projected[$unit['id']] = $unit;
            foreach ($shelters as $id => $shelter) {
                $unit = $projected[$shelter['instance_id']];
                $details[$id]['shelter_instance_id'] = (int)$unit['id']; $details[$id]['shelter_plot_id'] = (int)$shelter['plot_id'];
                $details[$id]['condition'] = (int)$unit['durability']; $details[$id]['max_condition'] = (int)$unit['max_durability'];
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $owned = $this->policy->owns($row); $detail = $details[$row['id']] ?? [];
            if (!$owned) $detail = array_intersect_key($detail, array_flip(['settlement_kind', 'population', 'climate', 'plot_kind', 'ordinal', 'unlocked']));
            unset($detail['node_id']);
            foreach (['template_revision_id', 'level', 'condition', 'max_condition', 'area', 'fertility', 'ordinal', 'unlocked', 'population', 'plot_limit', 'allow_building', 'garden_node_id', 'active_project_id'] as $field) if (isset($detail[$field])) $detail[$field] = (int)$detail[$field];
            $label = ($row['label'] ?? '') ?: $row['name'];
            // Legacy purchases named their single room after the house, which
            // made the correctly nested room look like another house on maps.
            if ($row['node_type'] === 'ROOM' && isset($purchasedRooms[$row['id']]) && $label === $purchasedRooms[$row['id']]['building_name']) $label = 'Комната';
            $result[] = ['id' => (int)$row['id'], 'type' => $row['node_type'], 'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
                'root_id' => (int)$row['root_id'], 'code' => $row['code'], 'name' => $row['name'], 'label' => $label, 'status' => $row['status'], 'visibility' => $row['visibility'], 'revision' => (int)$row['revision'], 'portable' => (bool)$row['portable'],
                'coordinates' => ['x' => (int)$row['position_x'], 'y' => (int)$row['position_y']],
                'footprint' => $row['footprint_json'] === null ? null : json_decode($row['footprint_json'], true, 512, JSON_THROW_ON_ERROR),
                'child_count' => (int)($counts[$row['id']]['amount'] ?? 0),
                'descendant_count' => (int)($descendants[$row['id']]['amount'] ?? 0),
                'population_total' => (int)($populations[$row['id']]['amount'] ?? 0),
                'owned_by_me' => $this->policy->ownsItems($row),
                'details' => (object)$detail, 'permissions' => ['manage' => $owned, 'administer' => $this->policy->isAdmin(), 'storage' => $this->policy->ownsItems($row)],
                'actions' => [['code' => 'open', 'allowed' => true, 'reasons' => []]]];
        }
        return $result;
    }
    private function positive($value, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value) || (float)$value > $maximum) throw new GameError('INVALID_PAGINATION', 'Некорректная страница.', 422);
        return (int)$value;
    }
}
