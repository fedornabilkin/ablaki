<?php
namespace common\modules\world\service;

use common\services\game\CanonicalJson;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Writes are called under the registry lock; parent_id and closure change together. */
class WorldTree
{
    public const TYPES = ['WORLD', 'REGION', 'SETTLEMENT', 'BUILDING', 'ROOM', 'PLOT', 'BED'];
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function get(int $id): array
    {
        $row = (new Query())->from('world_node')->where(['id' => $id])->one($this->db);
        if (!$row) throw new GameError('NODE_NOT_FOUND', 'Объект не найден.', 404);
        return $row;
    }
    public function assertParent(string $type, ?array $parent): void
    {
        if ($parent && $this->isShelter((int)$parent['id'])) throw new GameError('SHELTER_NOT_A_ROOM', 'В шалаше нельзя создавать помещения или места оборудования.');
        $allowed = ['WORLD' => ['REGION'], 'REGION' => ['SETTLEMENT'], 'SETTLEMENT' => ['BUILDING', 'PLOT'], 'BUILDING' => ['ROOM', 'PLOT'], 'PLOT' => ['BUILDING', 'BED', 'PLOT'], 'ROOM' => [], 'BED' => []];
        if (!in_array($type, self::TYPES, true) || (!$parent && $type !== 'WORLD') || ($parent && !in_array($type, $allowed[$parent['node_type']] ?? [], true))) throw new GameError('INVALID_PARENT', 'Здесь нельзя разместить такой объект.', 422);
        if (!$parent) return;
        if ($parent['status'] !== 'active' || (int)$parent['depth'] >= 32) throw new GameError('INVALID_PARENT', 'Родительский объект недоступен.');
        if ($parent['node_type'] === 'PLOT') {
            $plot = (new Query())->from('world_plot')->where(['node_id' => $parent['id']])->one($this->db);
            if ($type === 'PLOT' && ($plot['plot_kind'] ?? '') !== 'campsite') throw new GameError('INCOMPATIBLE_PLOT', 'Nested plots are only allowed on an estate.');
            if (($type === 'BUILDING' && empty($plot['allow_building'])) || ($type === 'BED' && ($plot['plot_kind'] ?? '') !== 'garden')) throw new GameError('INCOMPATIBLE_PLOT', 'Назначение участка не подходит.');
        }
    }
    public function create(array $values, array $details = []): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('World writes require a transaction.');
        $parent = isset($values['parent_id']) ? $this->get((int)$values['parent_id']) : null;
        $type = $values['node_type']; $this->assertParent($type, $parent);
        if ($type === 'PLOT' && $parent && $parent['node_type'] === 'PLOT' && ($details['plot_kind'] ?? '') !== 'garden') throw new GameError('INCOMPATIBLE_PLOT', 'Only a garden can be placed on an estate.');
        if ($parent && !isset($values['position_x']) && !isset($values['position_y'])) {
            $position = $this->nextPosition((int)$parent['id']);
            $values['position_x'] = $position['x']; $values['position_y'] = $position['y'];
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $values['code']) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $values['slug'])) throw new GameError('INVALID_NODE', 'Некорректный код объекта.', 422);
        if (!is_string($values['name']) || trim($values['name']) === '' || mb_strlen($values['name'], 'UTF-8') > 120) throw new GameError('INVALID_NODE', 'Некорректное название.', 422);
        $row = ['parent_id' => $parent ? (int)$parent['id'] : null, 'root_id' => $parent ? (int)$parent['root_id'] : null, 'node_type' => $type,
            'code' => $values['code'], 'slug' => $values['slug'], 'name' => trim($values['name']), 'label' => trim((string)($values['label'] ?? $values['name'])), 'owner_user_id' => $values['owner_user_id'] ?? null, 'portable' => (int)($values['portable'] ?? 0),
            'visibility' => $values['visibility'] ?? 'public', 'status' => 'active', 'depth' => $parent ? (int)$parent['depth'] + 1 : 0,
            'position_x' => $values['position_x'] ?? 0, 'position_y' => $values['position_y'] ?? 0, 'position' => $values['position'] ?? 0,
            'footprint_json' => WorldMapGeometry::normalize($values['footprint_json'] ?? null, $values['position_x'] ?? 0, $values['position_y'] ?? 0),
            'revision' => 1, 'created_at' => time(), 'updated_at' => time()];
        $row += array_intersect_key($values, WorldLayout::defaults($type, $details)) + WorldLayout::defaults($type, $details);
        WorldLayout::resize($this->db, 0, $row);
        if (!in_array($row['visibility'], ['public', 'private'], true)) throw new GameError('INVALID_VISIBILITY', 'Некорректная видимость.', 422);
        foreach (['position_x', 'position_y', 'position'] as $field) if (!is_int($row[$field]) || abs($row[$field]) > 1000000) throw new GameError('INVALID_POSITION', 'Некорректная позиция.', 422);
        if ($parent) foreach (WorldMapGeometry::cells($row['footprint_json'], (int)$row['position_x'], (int)$row['position_y']) as $cell) {
            $this->assertFreePosition((int)$parent['id'], $cell['x'], $cell['y']);
            if ($row['footprint_json'] !== null && !(new Query())->from('world_map_cell')->where(['parent_id' => $parent['id'], 'x' => $cell['x'], 'y' => $cell['y'], 'state' => 'open'])->exists($this->db))
                throw new GameError('MAP_CELL_CLOSED', 'Сначала откройте все ячейки полигона.', 422);
        }
        $this->db->createCommand()->insert('world_node', $row)->execute(); $id = (int)$this->db->getLastInsertID();
        if ($parent && !($type === 'BED' && empty($details['unlocked']))) {
            $where = ['parent_id' => (int)$parent['id'], 'x' => $row['position_x'], 'y' => $row['position_y']];
            $cell = (new Query())->from('world_map_cell')->where($where)->one($this->db);
            if (!$cell) $this->db->createCommand()->insert('world_map_cell', $where + ['state' => 'open', 'price' => '0.0000', 'operation_id' => null, 'created_at' => time(), 'updated_at' => time()])->execute();
            elseif ($cell['state'] !== 'open') $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'updated_at' => time()], $where)->execute();
        }
        if (!$parent) $this->db->createCommand()->update('world_node', ['root_id' => $id], ['id' => $id])->execute();
        $this->db->createCommand()->insert('world_node_closure', ['ancestor_id' => $id, 'descendant_id' => $id, 'distance' => 0])->execute();
        if ($parent) foreach ((new Query())->from('world_node_closure')->where(['descendant_id' => $parent['id']])->all($this->db) as $ancestor) {
            $this->db->createCommand()->insert('world_node_closure', ['ancestor_id' => $ancestor['ancestor_id'], 'descendant_id' => $id, 'distance' => (int)$ancestor['distance'] + 1])->execute();
        }
        if ($type !== 'WORLD') {
            $table = 'world_' . strtolower($type);
            $allowedDetails = ['REGION' => ['climate'], 'SETTLEMENT' => ['settlement_kind', 'population', 'plot_limit'], 'BUILDING' => ['building_kind', 'level', 'condition', 'max_condition', 'operational_status'], 'ROOM' => ['area', 'exposure_class'], 'PLOT' => ['plot_kind', 'area', 'fertility', 'allow_building'], 'BED' => ['garden_node_id', 'ordinal', 'unlocked']];
            $detailRow = array_intersect_key($details, array_flip(array_merge($allowedDetails[$type], ['template_revision_id'])));
            if ($type === 'BED' && (($details['garden_node_id'] ?? null) !== (int)$parent['id'] || !is_int($details['ordinal'] ?? null) || $details['ordinal'] < 1 || $details['ordinal'] > 10)) throw new GameError('INVALID_BED', 'Некорректная грядка.', 422);
            $this->db->createCommand()->insert($table, ['node_id' => $id] + $detailRow)->execute();
        }
        if ($type === 'BUILDING' && $row['owner_user_id'] !== null) BuildingFacilities::stockpile($this->db, $id, (int)$row['owner_user_id'], $details['building_kind'] ?? 'house');
        $this->touchAncestors($id);
        return $this->get($id);
    }
    public function assertFreePosition(int $parent, int $x, int $y, ?int $except = null): void
    {
        WorldLayout::assertCell($this->get($parent), $x, $y);
        $query = (new Query())->select(['id', 'position_x', 'position_y', 'footprint_json'])->from('world_node')->where(['parent_id' => $parent])->andWhere(['<>', 'status', 'archived']);
        if ($except !== null) $query->andWhere(['<>', 'id', $except]);
        foreach ($query->all($this->db) as $row)
            if (WorldMapGeometry::covers($row['footprint_json'], (int)$row['position_x'], (int)$row['position_y'], $x, $y))
                throw new GameError('MAP_CELL_OCCUPIED', 'В этих координатах уже расположен объект.', 422);
    }
    public function nextPosition(int $parent): array
    {
        $bounds = $this->get($parent); $occupied = [];
        foreach ((new Query())->select(['position_x', 'position_y', 'footprint_json'])->from('world_node')->where(['parent_id' => $parent])->andWhere(['<>', 'status', 'archived'])->all($this->db) as $row)
            foreach (WorldMapGeometry::cells($row['footprint_json'], (int)$row['position_x'], (int)$row['position_y']) as $cell)
                $occupied[$cell['x'] . ':' . $cell['y']] = true;
        $known = [];
        foreach ((new Query())->select(['x', 'y', 'state'])->from('world_map_cell')->where(['parent_id' => $parent])->all($this->db) as $cell)
            $known[$cell['x'] . ':' . $cell['y']] = $cell['state'];
        $best = null; $score = null;
        for ($y = (int)$bounds['map_origin_y']; $y < (int)$bounds['map_origin_y'] + (int)$bounds['map_height']; $y++)
            for ($x = (int)$bounds['map_origin_x']; $x < (int)$bounds['map_origin_x'] + (int)$bounds['map_width']; $x++) {
                if (isset($occupied[$x . ':' . $y])) continue;
                $state = $known[$x . ':' . $y] ?? null;
                if ($state !== null && $state !== 'open') continue;
                $candidate = [$state === 'open' ? 0 : 1, max(abs($x), abs($y)), abs($x) + abs($y), $y, $x];
                if ($score === null || $candidate < $score) { $score = $candidate; $best = compact('x', 'y'); }
            }
        if ($best !== null) return $best;
        throw new GameError('MAP_FULL', 'На карте не осталось свободных координат.', 422);
    }
    /** Also used by preview: an invalid action must not produce a confirmable quote. */
    public function previewMove(int $id, int $parentId): array
    {
        $node = $this->get($id); $parent = $this->get($parentId);
        if ((new Query())->from(['p' => 'world_construction'])->innerJoin(['c' => 'world_node_closure'], '[[c.descendant_id]]=[[p.node_id]]')->where(['c.ancestor_id' => $id, 'p.status' => ['constructing', 'paused']])->exists($this->db)) throw new GameError('CONSTRUCTION_FIXED', 'Сначала завершите или отмените строительство на этой территории.');
        if ($this->db->schema->getTableSchema('world_housing_place') && (new Query())->from(['p' => 'world_housing_place'])->innerJoin(['c' => 'world_node_closure'], '[[c.descendant_id]]=[[p.room_id]]')->where(['c.ancestor_id' => $id])->exists($this->db)) throw new GameError('HOUSING_MOVE_REQUIRED', 'Жильё закреплено за стоянкой. Для переноса территории нужен отдельный перенос жилья и истории ночлега.');
        if ($this->db->schema->getTableSchema('world_garden_purchase') && (new Query())->from('world_garden_purchase')->where(['node_id' => $id])->exists($this->db)) throw new GameError('FIXED_GARDEN', 'Огород закреплён за поселением. Перенос с сохранением прав и обязательств требует отдельной операции.');
        if ($this->isShelter($id)) throw new GameError('SHELTER_MOVE_REQUIRED', 'Для переноса сначала сложите шалаш на его стоянке.');
        if ($this->db->schema->getTableSchema('world_shelter_deployment') && (new Query())->from(['s' => 'world_shelter_deployment'])->innerJoin(['c' => 'world_node_closure'], '[[c.descendant_id]]=[[s.node_id]]')->where(['c.ancestor_id' => $id, 's.ended_at' => null])->exists($this->db)) throw new GameError('SHELTER_MOVE_REQUIRED', 'Перед переносом территории сложите размещённые шалаши: назначенный ночлег закреплён за местом.');
        if ($this->db->schema->getTableSchema('world_premises_purchase') && (new Query())->from('world_premises_purchase')->where(['or', ['building_id' => $id], ['room_id' => $id]])->exists($this->db)) throw new GameError('FIXED_PREMISES', 'Купленное помещение закреплено за площадкой. Для переезда нужна отдельная операция.');
        if ($node['status'] !== 'active') throw new GameError('NODE_INACTIVE', 'Объект недоступен.');
        if (empty($node['portable'])) throw new GameError('NODE_NOT_PORTABLE', 'Этот объект закреплён на месте. Сначала включите параметр «Можно перемещать» в настройках объекта.');
        if ((int)$node['parent_id'] === $parentId) throw new GameError('SAME_PARENT', 'Объект уже находится здесь.');
        if ($node['node_type'] === 'WORLD' || (int)$node['root_id'] !== (int)$parent['root_id']) throw new GameError('CROSS_WORLD_MOVE', 'Перенос между мирами недоступен.');
        if ($node['node_type'] === 'BED') throw new GameError('FIXED_GARDEN_BED', 'Грядка закреплена за своим огородом.');
        if ($node['footprint_json'] !== null) throw new GameError('MAP_FOOTPRINT_MOVE_REQUIRED', 'Сначала верните объект к размеру одной ячейки.', 422);
        if ((new Query())->from('world_map_cell')->where(['parent_id' => $id])->andWhere(['or', ['state' => 'discovered'], ['>', 'price', 0]])->exists($this->db))
            throw new GameError('MAP_RIGHTS_MOVE_REQUIRED', 'Объект с исследованными или купленными ячейками нельзя переносить обычной командой.', 422);
        $this->assertParent($node['node_type'], $parent);
        $descendants = (new Query())->from('world_node_closure')->where(['ancestor_id' => $id])->all($this->db);
        if (count($descendants) > 10000) throw new GameError('TREE_TOO_LARGE', 'Перенос требует отдельного задания.');
        $ids = array_map('intval', array_column($descendants, 'descendant_id'));
        if (in_array($parentId, $ids, true)) throw new GameError('TREE_CYCLE', 'Нельзя перенести объект внутрь самого себя.');
        $height = max(array_column($descendants, 'distance'));
        if ((int)$parent['depth'] + 1 + (int)$height > 32) throw new GameError('TREE_TOO_DEEP', 'Превышена глубина мира.');
        $duplicate = (new Query())->from('world_node')->where(['parent_id' => $parentId, 'slug' => $node['slug']])->andWhere(['<>', 'id', $id])->exists($this->db);
        if ($duplicate) throw new GameError('SLUG_OCCUPIED', 'В этом месте уже есть объект с таким адресом.');
        return ['node' => $node, 'parent' => $parent, 'descendants' => $descendants, 'ids' => $ids];
    }
    public function move(int $id, int $parentId): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('World writes require a transaction.');
        $prepared = $this->previewMove($id, $parentId);
        return $this->movePrepared($id, $parentId, $prepared);
    }
    /** One-time legacy repair, only to the original estate recorded by the purchase. */
    public function attachPurchasedGarden(int $id, int $parentId): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Garden repair requires a transaction.');
        $node = $this->get($id); $parent = $this->get($parentId);
        if ((int)$node['parent_id'] === $parentId) return $node;
        $purchase = (new Query())->from(['p' => 'world_garden_purchase'])->innerJoin(['m' => 'world_membership'], '[[m.id]]=[[p.membership_id]]')
            ->where(['p.node_id' => $id, 'm.starter_site_id' => $parentId, 'm.user_id' => $node['owner_user_id']])->exists($this->db);
        if (!$purchase || $node['node_type'] !== 'PLOT' || $node['status'] !== 'active' || (int)$node['owner_user_id'] !== (int)$parent['owner_user_id'] || (int)$node['parent_id'] !== (int)$parent['parent_id'] || (int)$node['root_id'] !== (int)$parent['root_id']) throw new GameError('INVALID_GARDEN_ESTATE', 'Стоянка не соответствует покупке огорода.');
        $this->assertParent('PLOT', $parent);
        $descendants = (new Query())->from('world_node_closure')->where(['ancestor_id' => $id])->all($this->db);
        $ids = array_map('intval', array_column($descendants, 'descendant_id'));
        return $this->movePrepared($id, $parentId, compact('node', 'parent', 'descendants', 'ids'));
    }
    private function movePrepared(int $id, int $parentId, array $prepared): array
    {
        $node = $prepared['node']; $parent = $prepared['parent']; $descendants = $prepared['descendants']; $ids = $prepared['ids'];
        $position = $this->nextPosition($parentId);
        $ancestors = (new Query())->from('world_node_closure')->where(['descendant_id' => $parentId])->all($this->db);
        $this->touchAncestors($id);
        $this->db->createCommand()->delete('world_node_closure', ['and', ['descendant_id' => $ids], ['not in', 'ancestor_id', $ids]])->execute();
        foreach ($ancestors as $ancestor) foreach ($descendants as $descendant) {
            $this->db->createCommand()->insert('world_node_closure', ['ancestor_id' => $ancestor['ancestor_id'], 'descendant_id' => $descendant['descendant_id'], 'distance' => (int)$ancestor['distance'] + 1 + (int)$descendant['distance']])->execute();
        }
        $delta = (int)$parent['depth'] + 1 - (int)$node['depth'];
        $this->db->createCommand()->update('world_node', ['depth' => new Expression('[[depth]] + :delta', [':delta' => $delta]), 'revision' => new Expression('[[revision]] + 1'), 'updated_at' => time()], ['id' => $ids])->execute();
        $this->db->createCommand()->update('world_node', ['parent_id' => $parentId, 'position_x' => $position['x'], 'position_y' => $position['y']], ['id' => $id])->execute();
        if (!(new Query())->from('world_map_cell')->where(['parent_id' => $parentId, 'x' => $position['x'], 'y' => $position['y']])->exists($this->db))
            $this->db->createCommand()->insert('world_map_cell', ['parent_id' => $parentId, 'x' => $position['x'], 'y' => $position['y'], 'state' => 'open', 'price' => '0.0000', 'operation_id' => null, 'created_at' => time(), 'updated_at' => time()])->execute();
        $this->touchAncestors($id);
        return $this->get($id);
    }
    public function previewArchive(int $id): array
    {
        $node = $this->get($id);
        if ($node['status'] !== 'active') throw new GameError('NODE_INACTIVE', 'Объект недоступен.');
        if ($node['node_type'] === 'WORLD') throw new GameError('ROOT_REQUIRED', 'Корень мира нельзя архивировать.');
        if ((new Query())->from('world_node')->where(['parent_id' => $id])->andWhere(['<>', 'status', 'archived'])->exists($this->db)) throw new GameError('NODE_NOT_EMPTY', 'Сначала освободите дочерние объекты.');
        foreach (['world_membership' => 'starter_site_id', 'world_construction' => 'node_id', 'craft_storage' => 'node_id', 'economy_subject' => 'node_id'] as $table => $column) {
            if ($this->db->schema->getTableSchema($table) && (new Query())->from($table)->where([$column => $id])->exists($this->db)) throw new GameError('NODE_IN_USE', 'Объект связан с имуществом или назначениями.');
        }
        return $node;
    }
    public function archive(int $id): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('World writes require a transaction.');
        $this->previewArchive($id);
        $this->db->createCommand()->update('world_node', ['status' => 'archived', 'revision' => new Expression('[[revision]] + 1'), 'updated_at' => time()], ['id' => $id])->execute();
        $this->touchAncestors($id);
        return $this->get($id);
    }
    public function isShelter(int $id): bool
    {
        return $this->db->schema->getTableSchema('world_shelter_deployment') && (new Query())->from('world_shelter_deployment')->where(['node_id' => $id])->exists($this->db);
    }
    /** Only after the canonical unit has left its retired deployment storage. */
    public function foldShelter(int $id): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Shelter folding requires a transaction.');
        $deployment = (new Query())->from('world_shelter_deployment')->where(['node_id' => $id, 'active_instance_id' => null])->andWhere(['not', ['ended_at' => null]])->one($this->db);
        $storage = (new Query())->from('craft_storage')->where(['node_id' => $id, 'kind' => 'shelter', 'status' => 'retired'])->one($this->db);
        if (!$deployment || !$storage || (new Query())->from('craft_inventory')->where(['storage_id' => $storage['id']])->andWhere(['>', 'item_quantity', 0])->exists($this->db)) throw new \LogicException('Shelter still contains an asset.');
        $this->db->createCommand()->update('world_node', ['status' => 'archived', 'revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $id])->execute();
        $this->touchAncestors($id);
    }
    private function touchAncestors(int $id): void
    {
        $ids = (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $id])->andWhere(['>', 'distance', 0])->column($this->db);
        if ($ids) $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]] + 1'), 'updated_at' => time()], ['id' => $ids])->execute();
    }
    public function audit(int $user, string $action, string $reason, array $before, array $after, string $operation): void
    {
        $this->db->createCommand()->insert('world_audit', ['actor_user_id' => $user, 'node_id' => $after['id'], 'action' => $action, 'reason' => $reason,
            'before_json' => CanonicalJson::encode($before), 'after_json' => CanonicalJson::encode($after), 'operation_id' => $operation, 'created_at' => time()])->execute();
    }
}
