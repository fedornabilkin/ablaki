<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Called by onboarding/backfill now, and paid construction later; never from GET. */
class PlacementProvisioner
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function equipmentCells(int $node, int $user = 0): array
    {
        $schema = $this->db->schema->getTableSchema('craft_storage');
        if (!$schema || !isset($schema->columns['node_id'])) return [];
        $location = (new Query())->from('world_node')->where(['id' => $node])->one($this->db);
        if (!$location) return [];
        $rows = (new Query())->select(['i.*', 's.owner_user_id'])->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
            ->where(['s.node_id' => $node, 's.kind' => 'placement', 's.status' => 'active'])->andWhere(['>', 'i.item_quantity', 0])->orderBy(['i.slot' => SORT_ASC])->all($this->db);
        $definitions = $this->db->schema->getTableSchema('craft_item') ? (new Query())->from('craft_item')->where(['id' => array_column($rows, 'item_id')])->indexBy('id')->all($this->db) : [];
        $properties = \common\modules\world\modules\craft\models\domain\ItemCharacteristics::batch($this->db, array_values($definitions));
        $units = $this->db->schema->getTableSchema('craft_equipment_instance') ? (new Query())->from('craft_equipment_instance')->where(['inventory_id' => array_column($rows, 'id'), 'status' => 'active'])->all($this->db) : [];
        $durability = [];
        foreach ((new \common\modules\world\modules\craft\models\domain\EquipmentExposure($this->db))->projectedBatch($units) as $unit) $durability[$unit['inventory_id']] = $unit;
        $containers = $this->db->schema->getTableSchema('craft_container') ? (new Query())->from('craft_container')->where(['id' => array_column($rows, 'id')])->indexBy('id')->all($this->db) : [];
        $lodgingItems = $this->db->schema->getTableSchema('world_furnishing_rule') ? (new Query())->select('item_id')->from('world_furnishing_rule')->where(['item_id' => array_column($rows, 'item_id'), 'lodging' => 1])->column($this->db) : [];
        $result = []; $width = (int)$location['map_width'];
        foreach ($rows as $row) {
            $index = (int)$row['slot'] - 1;
            if ($index < 0 || $index >= $width * (int)$location['map_height']) continue;
            $cell = ['x' => (int)$location['map_origin_x'] + $index % $width, 'y' => (int)$location['map_origin_y'] + intdiv($index, $width)];
            if (isset($definitions[$row['item_id']])) {
                $item = $definitions[$row['item_id']];
                $traits = $properties[$item['id']];
                if (isset($durability[$row['id']])) $traits[] = ['label' => 'Прочность', 'value' => $durability[$row['id']]['durability'] . ' / ' . $durability[$row['id']]['max_durability']];
                if (isset($containers[$row['id']])) $traits[] = ['label' => 'Прочность', 'value' => $containers[$row['id']]['durability'] . ' / ' . $containers[$row['id']]['max_durability']];
                $cell += ['item_id' => (int)$item['id'], 'lodging' => in_array((int)$item['id'], array_map('intval', $lodgingItems), true), 'name' => trim($item['name']), 'icon' => trim($item['icon']), 'description' => mb_substr(trim($item['description'] ?? ''), 0, 2000), 'quantity' => (int)$row['item_quantity'], 'characteristics' => $traits,
                    'inventory_id' => $user > 0 && (int)$row['owner_user_id'] === $user ? (int)$row['id'] : null,
                    'storage_id' => $user > 0 && (int)$row['owner_user_id'] === $user ? (int)$row['storage_id'] : null];
            }
            $result[] = $cell;
        }
        return $result;
    }
    public function campsite(int $user, int $node): int
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Placement provisioning requires a transaction.');
        $site = (new Query())->from('world_node')->where(['id' => $node, 'owner_user_id' => $user, 'node_type' => 'PLOT', 'status' => 'active'])->one($this->db);
        $plot = (new Query())->from('world_plot')->where(['node_id' => $node, 'plot_kind' => 'campsite'])->one($this->db);
        if (!$site || !$plot) throw new \LogicException('Expected the owned starter campsite.');
        $key = 'placement:node:' . $node;
        $storage = (new Query())->from('craft_storage')->where(['identity_key' => $key])->one($this->db);
        if (!$storage) {
            $this->db->createCommand()->insert('craft_storage', ['identity_key' => $key, 'kind' => 'placement', 'owner_user_id' => $user, 'node_id' => $node, 'capacity' => 4])->execute();
            $storage = ['id' => (int)$this->db->getLastInsertID()];
        }
        for ($position = 1; $position <= 4; $position++) {
            $where = ['storage_id' => $storage['id'], 'position' => $position];
            if (!(new Query())->from('world_slot')->where($where)->exists($this->db)) $this->db->createCommand()->insert('world_slot', $where + ['code' => 'outdoor-' . $position, 'slot_type' => 'equipment', 'size' => 1, 'exposure_class' => 'outdoor', 'compatibility_json' => '{}'])->execute();
        }
        return (int)$storage['id'];
    }
    public function interior(\common\modules\world\models\Node $node): int
    {
        if (!$this->db->getTransaction() || $node->status !== 'active' || !$node->owner_user_id) throw new \LogicException('Expected an active owned object in the build transaction.');
        $key = 'placement:node:' . $node->id;
        $storage = (new Query())->from('craft_storage')->where(['identity_key' => $key])->one($this->db);
        $capacity = min(100, (int)$node->map_width * (int)$node->map_height);
        $exposure = 'indoor';
        if (!$storage) {
            $this->db->createCommand()->insert('craft_storage', ['identity_key' => $key, 'kind' => 'placement', 'owner_user_id' => $node->owner_user_id, 'node_id' => $node->id, 'capacity' => $capacity])->execute();
            $storage = ['id' => (int)$this->db->getLastInsertID()];
        }
        $this->db->createCommand()->update('craft_storage', ['capacity' => $capacity], ['id' => $storage['id']])->execute();
        for ($position = 1; $position <= $capacity; $position++) {
            $where = ['storage_id' => $storage['id'], 'position' => $position];
            if (!(new Query())->from('world_slot')->where($where)->exists($this->db)) $this->db->createCommand()->insert('world_slot', $where + ['code' => 'interior-' . $position, 'slot_type' => 'equipment', 'size' => 1, 'exposure_class' => $exposure ?: 'indoor', 'compatibility_json' => '{}'])->execute();
            $this->db->createCommand()->update('world_slot', ['slot_type' => 'equipment', 'size' => 1, 'exposure_class' => $exposure, 'compatibility_json' => '{}'], $where)->execute();
        }
        return (int)$storage['id'];
    }
    /** One initial cell at every level. Existing researched or paid rights are retained. */
    public function openObjectMap(\common\modules\world\models\Node $node, ?string $operation = null): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Map provisioning requires a transaction.');
        if ($node->status !== 'active' || (new Query())->from('world_map_cell')->where(['parent_id' => $node->id])->exists($this->db)) return;
        $x = max((int)$node->map_origin_x, min(0, (int)$node->map_origin_x + (int)$node->map_width - 1));
        $y = max((int)$node->map_origin_y, min(0, (int)$node->map_origin_y + (int)$node->map_height - 1));
        $this->db->createCommand()->insert('world_map_cell', ['parent_id' => $node->id, 'x' => $x, 'y' => $y,
            'state' => 'open', 'price' => '0.0000', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time()])->execute();
    }
    public function openInteriorMap(\common\modules\world\models\Node $node, ?string $operation = null): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Interior map provisioning requires a transaction.');
        if ($node->status !== 'active' || (!in_array($node->node_type, ['BUILDING', 'ROOM', 'PLACE', 'CHEST', 'BED'], true) && $node->plot_kind !== 'garden')) return;
        for ($y = (int)$node->map_origin_y; $y < (int)$node->map_origin_y + (int)$node->map_height; $y++)
            for ($x = (int)$node->map_origin_x; $x < (int)$node->map_origin_x + (int)$node->map_width; $x++) {
                $where = ['parent_id' => $node->id, 'x' => $x, 'y' => $y];
                if (!(new Query())->from('world_map_cell')->where($where)->exists($this->db)) $this->db->createCommand()->insert('world_map_cell', $where + ['state' => 'open', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time()])->execute();
            }
    }
}
