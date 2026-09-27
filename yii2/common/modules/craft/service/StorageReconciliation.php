<?php
namespace common\modules\craft\service;

use yii\db\Connection;
use yii\db\Query;

/** Explicit report for the migration window. Does not repair or enable storage. */
class StorageReconciliation
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function report(): array
    {
        $counts = []; $samples = []; $rows = 0;
        $issue = static function (string $code, int $id) use (&$counts, &$samples): void {
            $counts[$code] = ($counts[$code] ?? 0) + 1;
            if (count($samples) < 100) $samples[] = ['code' => $code, 'inventory_id' => $id];
        };
        $equipment = new EquipmentInstances($this->db);
        foreach ((new Query())->from('craft_inventory')->orderBy(['id' => SORT_ASC])->each(200, $this->db) as $row) {
            $rows++; $id = (int)$row['id']; $quantity = (int)$row['item_quantity'];
            if (!(new Query())->from('user')->where(['id' => $row['user_id']])->exists($this->db)) $issue('MISSING_OWNER', $id);
            if ($quantity < 0 || ($quantity > 0 && !$row['item_id'])) { $issue('INVALID_QUANTITY', $id); continue; }
            $storage = (new Query())->from('craft_storage')->where(['id' => $row['storage_id']])->one($this->db);
            if (!$storage || ($quantity > 0 && $storage['status'] !== 'active') || (int)$storage['owner_user_id'] !== (int)$row['user_id']) { $issue('INVALID_STORAGE_OWNER', $id); continue; }
            if (!in_array($storage['kind'], ['backpack', 'chest', 'placement', 'stockpile', 'recovery', 'construction'], true)) $issue('INVALID_STORAGE_KIND', $id);
            if (!$quantity) { if ($row['slot'] !== null) $issue('EMPTY_POSITION', $id); continue; }
            if ((int)$row['slot'] < 1) $issue('INVALID_POSITION', $id);
            $expectedContainer = $storage['kind'] === 'chest' ? (int)$storage['container_inventory_id'] : null;
            if (($row['container_id'] === null ? null : (int)$row['container_id']) !== $expectedContainer) $issue('LEGACY_PROJECTION_MISMATCH', $id);
            $item = (new Query())->from('craft_item')->where(['id' => $row['item_id']])->one($this->db);
            if (!$item) { $issue('MISSING_ITEM', $id); continue; }
            $units = $equipment->units($id);
            if (count($units) !== ($equipment->tracked($item) ? $quantity : 0)) $issue('INSTANCE_QUANTITY_MISMATCH', $id);
            foreach ($units as $unit) if ((int)$unit['item_id'] !== (int)$row['item_id'] || (int)$unit['durability'] < 0 || (int)$unit['durability'] > (int)$unit['max_durability']) $issue('INVALID_INSTANCE', $id);
            if (($item['storage_kind'] ?? '') === 'chest') {
                $inner = (new Query())->from('craft_storage')->where(['container_inventory_id' => $id, 'kind' => 'chest', 'status' => 'active'])->one($this->db);
                $container = (new Query())->from('craft_container')->where(['id' => $id])->one($this->db);
                if ($quantity !== 1 || $storage['kind'] === 'chest' || !$inner || !$container || (int)$inner['owner_user_id'] !== (int)$row['user_id'] || (int)$container['user_id'] !== (int)$row['user_id'] || (int)$inner['capacity'] !== (int)$container['capacity']) $issue('INVALID_CHEST', $id);
                if ($container && ((int)$container['capacity'] < 1 || (int)$container['max_durability'] < 1 || (int)$container['durability'] < 0 || (int)$container['durability'] > (int)$container['max_durability'])) $issue('INVALID_CHEST_CONDITION', $id);
            }
            if ($storage['kind'] === 'placement' && ($quantity !== 1 || !(new Query())->from('world_slot')->where(['storage_id' => $storage['id'], 'position' => $row['slot']])->exists($this->db))) $issue('INVALID_PLACEMENT', $id);
        }
        $duplicates = (new Query())->select(['storage_id', 'slot'])->from('craft_inventory')->where(['not', ['slot' => null]])->andWhere(['not', ['storage_id' => null]])->groupBy(['storage_id', 'slot'])->having('COUNT(*)>1')->all($this->db);
        if ($duplicates) $counts['DUPLICATE_POSITION'] = count($duplicates);
        foreach ((new Query())->from(['e' => 'craft_equipment_instance'])->leftJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->where(['e.status' => 'active'])->andWhere(['or', ['i.id' => null], ['<=', 'i.item_quantity', 0]])->select('e.id')->each(200, $this->db) as $unit) $issue('ORPHAN_INSTANCE', (int)$unit['id']);
        if (!(new CraftStorage($this->db))->isCanonical()) {
            $wear = (new Query())->select(['i.user_id', 'e.item_id', 'inventory_id' => new \yii\db\Expression('MIN([[i.id]])'), 'damage' => new \yii\db\Expression('SUM([[e.max_durability]]-[[e.durability]])')])
                ->from(['e' => 'craft_equipment_instance'])->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->where(['e.status' => 'active'])->groupBy(['i.user_id', 'e.item_id']);
            foreach ($wear->each(200, $this->db) as $sum) {
                $legacy = (int)(new Query())->select('wear')->from('craft_tool_wear')->where(['user_id' => $sum['user_id'], 'item_id' => $sum['item_id']])->scalar($this->db);
                if ($legacy !== (int)$sum['damage']) $issue('WEAR_TOTAL_MISMATCH', (int)$sum['inventory_id']);
            }
            foreach ((new Query())->from(['w' => 'craft_tool_wear'])->leftJoin(['a' => 'craft_wear_assignment'], '[[a.user_id]]=[[w.user_id]] AND [[a.item_id]]=[[w.item_id]]')->where(['>', 'w.wear', 0])->andWhere(['or', ['a.instance_id' => null], new \yii\db\Expression('[[a.wear]]<>[[w.wear]]')])->select('w.user_id')->each(200, $this->db) as $missing) $issue('UNASSIGNED_LEGACY_WEAR', 0);
        }
        return ['generated_at' => time(), 'inventory_rows' => $rows, 'issues' => (object)$counts, 'samples' => $samples, 'consistent' => !$counts, 'activation_allowed' => false];
    }
}
