<?php
namespace common\modules\world\service;

use common\modules\world\model\WorldNodeForm;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Administrative changes use the same registry lock, revisions, command log and audit as gameplay. */
class WorldNodeEditor
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    public function snapshot(int $id): array
    {
        $node = (new WorldTree($this->db))->get($id);
        $details = $node['node_type'] === 'WORLD' ? [] : (new Query())->from('world_' . strtolower($node['node_type']))->where(['node_id' => $id])->one($this->db);
        if ($details === false) throw new GameError('NODE_DETAILS_MISSING', 'Данные объекта требуют восстановления.');
        return ['node' => $node, 'details' => $details];
    }
    /** Includes historical commercial bindings: an editor must not rewrite purchased entitlements. */
    public function usage(int $id, bool $archiving = false, bool $emptyPlacementAllowed = false): array
    {
        $ids = (new Query())->select('descendant_id')->from('world_node_closure')->where(['ancestor_id' => $id]);
        $refs = ['world_membership' => ['world_id', 'starter_site_id'], 'craft_storage' => ['node_id'], 'economy_subject' => ['node_id'],
            'world_construction' => ['node_id'], 'world_construction_site' => ['plot_id'],
            'world_premises_offer' => ['settlement_id'], 'world_premises_purchase' => ['plot_id', 'building_id', 'room_id'],
            'world_repair_contract' => ['building_id'],
            'world_building_demolition' => ['building_id', 'plot_id'],
            'npc' => ['world_id', 'home_node_id'], 'npc_hire_offer' => ['settlement_id'], 'world_job_position' => ['building_id'],
            'npc_training_program' => ['settlement_id'], 'production_order' => ['node_id'], 'world_crop_cycle' => ['bed_id'],
            'world_economy_policy' => ['world_id'], 'world_period_checkpoint' => ['node_id', 'world_id'],
            'world_event' => ['scope_node_id'], 'quest_instance' => ['scope_node_id'],
            'world_room_extension' => ['building_id', 'room_id'], 'world_capacity_place' => ['node_id'], 'world_capacity_entitlement' => ['node_id'], 'world_forced_demolition' => ['node_id'],
            'world_garden_offer' => ['settlement_id'], 'world_garden_purchase' => ['node_id'], 'world_expansion_policy' => ['node_id'],
            'world_expansion_entitlement' => ['node_id'], 'world_equipment_expansion' => ['recipient_node_id'],
            'world_shelter_deployment' => ['node_id', 'plot_id'], 'world_housing_place' => ['room_id', 'plot_id'],
            'world_night_policy' => ['world_id'], 'economy_purchase_order' => ['node_id'], 'economy_order_fulfillment' => ['site_node_id']];
        $found = [];
        foreach ($refs as $table => $columns) {
            if (!$this->db->schema->getTableSchema($table)) continue;
            $where = ['or']; foreach ($columns as $column) $where[] = [$column => $ids];
            $query = (new Query())->from($table)->where($where);
            if ($emptyPlacementAllowed && $table === 'craft_storage') $query->andWhere(['or', ['<>', 'kind', 'placement'],
                ['id' => (new Query())->select('storage_id')->from('craft_inventory')->where(['>', 'item_quantity', 0])]]);
            if ($archiving && $table === 'craft_storage') $query->andWhere(['or', ['<>', 'status', 'retired'], ['>', 'capacity', 0],
                ['id' => (new Query())->select('storage_id')->from('craft_inventory')->where(['>', 'item_quantity', 0])]]);
            if ($query->exists($this->db)) $found[] = $table;
        }
        if ($this->db->schema->getTableSchema('world_map_cell') && (new Query())->from('world_map_cell')->where(['parent_id' => $ids])
            ->andWhere(['or', ['state' => 'discovered'], ['>', 'price', 0]])->exists($this->db)) $found[] = 'world_map_cell';
        return $found;
    }
    private function requireUnused(int $id, bool $archiving = false, bool $emptyPlacementAllowed = false): void
    {
        if ($this->usage($id, $archiving, $emptyPlacementAllowed)) throw new GameError('NODE_IN_USE', 'Объект связан с игрой, имуществом или финансами. Здесь можно менять название и положение на карте; состав, владельца и игровые параметры изменяйте соответствующими игровыми действиями.');
    }
    private function prepare(array $input, string $action): array
    {
        $tree = new WorldTree($this->db); $before = $input['id'] ? $this->snapshot($input['id']) : null;
        $revisions = [];
        if ($before) {
            if ((int)$before['node']['revision'] !== $input['revision']) throw new GameError('REVISION_CHANGED', 'Объект изменился после открытия формы. Откройте его заново.');
            if ($before['node']['status'] !== 'active') throw new GameError('NODE_INACTIVE', 'Объект уже удалён в архив.');
            $revisions['node:' . $input['id']] = $input['revision'];
        }
        if ($action === 'delete') {
            $this->requireUnused($input['id'], true);
            if ((new Query())->from('world_registry')->where(['active_world_id' => $input['id']])->exists($this->db)) throw new GameError('ACTIVE_WORLD', 'Действующий мир нельзя удалить.');
            if ((new Query())->from('world_node')->where(['parent_id' => $input['id'], 'status' => 'active'])->exists($this->db)) throw new GameError('NODE_NOT_EMPTY', 'Сначала удалите или перенесите дочерние объекты.');
            return ['terms' => ['before' => $before, 'after' => ['status' => 'archived']], 'revisions' => $revisions];
        }
        $v = $input['values']; $d = $input['details']; $type = $v['node_type'];
        // Revalidate the stored command using the same typed contract as the HTML form.
        $form = new WorldNodeForm($type); $form->setAttributes($v + $d + ['reason' => $input['reason'], 'revision' => $input['revision']]);
        if (!$form->validate() || ($type === 'WORLD' && $v['parent_id'] !== null)) throw new GameError('INVALID_NODE', 'Проверьте поля объекта.', 422);
        if ($before && ($before['node']['node_type'] !== $type || $before['node']['code'] !== $v['code'])) throw new GameError('IMMUTABLE_NODE', 'Тип и постоянный код существующего объекта изменять нельзя.', 422);
        $parent = $v['parent_id'] ? $tree->get($v['parent_id']) : null;
        $tree->assertParent($type, $parent);
        if ($parent && in_array($type, ['ROOM', 'BED'], true) && (int)$v['owner_user_id'] !== (int)$parent['owner_user_id']) throw new GameError('OWNER_MISMATCH', 'Комната или грядка должна принадлежать владельцу родительского объекта.', 422);
        if ($parent) {
            $revisions['node:' . $parent['id']] = (int)$parent['revision'];
            if ((new Query())->from(['c' => 'world_node_closure'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[c.ancestor_id]]')->where(['c.descendant_id' => $parent['id']])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('INVALID_PARENT', 'Родитель находится в архиве.');
        }
        if ($v['owner_user_id'] !== null && !(new Query())->from('user')->where(['id' => $v['owner_user_id'], 'blocked_at' => null])->exists($this->db)) throw new GameError('OWNER_UNAVAILABLE', 'Владелец не найден или заблокирован.', 422);
        if ((new Query())->from('world_node')->where(['code' => $v['code']])->andWhere(['<>', 'id', $input['id']])->exists($this->db)
            || (new Query())->from('world_node')->where(['parent_id' => $v['parent_id'], 'slug' => $v['slug']])->andWhere(['<>', 'id', $input['id']])->exists($this->db)) throw new GameError('CODE_OCCUPIED', 'Код или адрес объекта уже занят.', 422);
        if (!$before && $parent && in_array($parent['node_type'], ['BUILDING', 'PLOT'], true)) $this->requireUnused((int)$parent['id'], false, $parent['node_type'] === 'BUILDING');
        if ($before) {
            $old = new WorldNodeForm($type); $old->populate($before); $oldInput = $old->payload($input['id']);
            if ($oldInput['details'] !== $d) $this->requireUnused($input['id'], false, in_array($type, ['BUILDING', 'ROOM'], true));
            $structural = false;
            foreach (['parent_id', 'owner_user_id', 'visibility'] as $field) $structural = $structural || $oldInput['values'][$field] !== $v[$field];
            if ($structural) $this->requireUnused($input['id']);
            if ($oldInput['values']['owner_user_id'] !== $v['owner_user_id'] && (new Query())->from('world_node')->where(['parent_id' => $input['id']])->exists($this->db)) throw new GameError('OWNER_SUBTREE', 'Смена владельца объекта с дочерними объектами требует отдельной передачи владения.');
            if ($oldInput['values']['parent_id'] !== $v['parent_id']) {
                if (!$parent) throw new GameError('INVALID_PARENT', 'Родитель обязателен.', 422);
                if (in_array($parent['node_type'], ['BUILDING', 'PLOT'], true)) $this->requireUnused((int)$parent['id']);
                $tree->previewMove($input['id'], (int)$parent['id']);
            }
        }
        if ($type === 'BUILDING' && $d['operational_status'] === 'constructing' && ($before['details']['operational_status'] ?? null) !== 'constructing') throw new GameError('CONSTRUCTION_REQUIRED', 'Строительство начинается через покупку проекта, с резервом материалов и бюджета.', 422);
        if ($type === 'BUILDING' && ($d['condition'] > $d['max_condition'] || ($d['operational_status'] === 'active' && $d['condition'] < 1)
            || ($d['operational_status'] === 'destroyed' && $d['condition'] !== 0))) throw new GameError('INVALID_CONDITION', 'Прочность должна соответствовать максимуму и состоянию постройки.', 422);
        if ($type === 'ROOM' && $before) {
            $slots = (new Query())->from(['s' => 'world_slot'])->innerJoin(['t' => 'craft_storage'], '[[t.id]]=[[s.storage_id]]')->where(['t.node_id' => $input['id']]);
            if ((int)(clone $slots)->sum('s.size', $this->db) > $d['area']) throw new GameError('ROOM_CAPACITY', 'Площадь не может быть меньше суммы размеров мест оборудования.', 422);
            if ((clone $slots)->andWhere(['<>', 's.exposure_class', $d['exposure_class']])->exists($this->db)) throw new GameError('ROOM_EXPOSURE', 'Перед сменой защиты удалите пустые места и создайте их заново с новыми условиями.');
        }
        if ($type === 'PLOT') {
            if ($d['plot_kind'] !== 'garden' && (new Query())->from('world_node')->where(['parent_id' => $input['id'], 'node_type' => 'BED', 'status' => 'active'])->exists($this->db)) throw new GameError('GARDEN_HAS_BEDS', 'Участок с грядками должен оставаться огородом.');
            if (!$d['allow_building'] && (new Query())->from('world_node')->where(['parent_id' => $input['id'], 'node_type' => 'BUILDING', 'status' => 'active'])->exists($this->db)) throw new GameError('PLOT_HAS_BUILDINGS', 'На участке уже есть постройки.');
        }
        if ($type === 'BED' && (new Query())->from('world_bed')->where(['garden_node_id' => $v['parent_id'], 'ordinal' => $d['ordinal']])->andWhere(['<>', 'node_id', $input['id']])->exists($this->db)) throw new GameError('BED_OCCUPIED', 'Этот номер грядки уже занят, в том числе архивной записью.', 422);
        if ($parent && (!$before || (int)$before['node']['parent_id'] !== (int)$v['parent_id']
            || (int)$before['node']['position_x'] !== (int)$v['position_x'] || (int)$before['node']['position_y'] !== (int)$v['position_y']
            || $before['node']['footprint_json'] !== $v['footprint_json'])) {
            foreach (WorldMapGeometry::cells($v['footprint_json'], $v['position_x'], $v['position_y']) as $cell) {
                $tree->assertFreePosition((int)$parent['id'], $cell['x'], $cell['y'], $input['id'] ?: null);
                if ($v['footprint_json'] !== null && !(new Query())->from('world_map_cell')->where(['parent_id' => $parent['id'], 'x' => $cell['x'], 'y' => $cell['y'], 'state' => 'open'])->exists($this->db))
                    throw new GameError('MAP_CELL_CLOSED', 'Сначала откройте все ячейки полигона.', 422);
            }
        }
        return ['terms' => ['before' => $before, 'after' => ['node' => $v, 'details' => $d]], 'revisions' => $revisions];
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.admin.' . $action, $input, function () use ($input, $action) { return $this->prepare($input, $action); });
    }
    public function execute(int $user, array $record): array
    {
        $action = $record['action']; $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $record['key'], 'world.admin.' . $action, $record['input'], $record['quote']['quote_id'], (array)$record['quote']['expected_revisions'],
            function (array $input, array $terms, string $operation) use ($user, $action, $bus) {
                $current = $this->prepare($input, $action);
                if (CanonicalJson::encode($current['terms']) !== CanonicalJson::encode($terms)) throw new GameError('NODE_CHANGED', 'Условия изменились. Повторите предварительный просмотр.');
                $tree = new WorldTree($this->db); $before = $terms['before'];
                if ($action === 'create') $id = (int)$tree->create($input['values'], $input['details'])['id'];
                else {
                    $id = $input['id'];
                    if ($action === 'delete') $values = ['status' => 'archived'];
                    else {
                        $values = $input['values']; unset($values['node_type'], $values['code'], $values['parent_id']);
                        if ((int)$before['node']['parent_id'] !== (int)$input['values']['parent_id']) $tree->move($id, $input['values']['parent_id']);
                        if ($input['details']) $this->db->createCommand()->update('world_' . strtolower($before['node']['node_type']), $input['details'], ['node_id' => $id])->execute();
                    }
                    $this->db->createCommand()->update('world_node', $values, ['id' => $id])->execute();
                    if ($input['values']['parent_id'] !== null && $input['values']['footprint_json'] === null
                        && !($before['node']['node_type'] === 'BED' && empty($input['details']['unlocked']))) {
                        $cell = ['parent_id' => (int)$input['values']['parent_id'], 'x' => (int)$input['values']['position_x'], 'y' => (int)$input['values']['position_y']];
                        $existing = (new Query())->from('world_map_cell')->where($cell)->one($this->db);
                        if (!$existing) $this->db->createCommand()->insert('world_map_cell', $cell + ['state' => 'open', 'price' => '0.0000', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time()])->execute();
                        elseif ($existing['state'] !== 'open') $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'operation_id' => $operation, 'updated_at' => time()], $cell)->execute();
                    }
                    $ancestors = (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $id])->column($this->db);
                    $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $ancestors])->execute();
                }
                $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
                $after = $this->snapshot($id);
                $tree->audit($user, 'world.admin.' . $action, $input['reason'], $before ?: [], ['id' => $id] + $after, $operation);
                $bus->emit($operation, $user, 'world.admin.' . $action, ['node_id' => $id, 'revision' => (int)$after['node']['revision']]);
                return ['node_id' => $id];
            });
    }
}
