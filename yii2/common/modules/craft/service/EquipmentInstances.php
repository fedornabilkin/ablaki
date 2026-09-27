<?php
namespace common\modules\craft\service;

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** Identity belongs to one physical unit, independently of the stack containing it. */
class EquipmentInstances
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function tracked(array $item): bool
    {
        if (($item['storage_kind'] ?? 'none') === 'chest') return false;
        return in_array($item['kind'], ['tool', 'station'], true)
            || (new Query())->from('craft_station')->where(['item_id' => $item['id']])->exists($this->db)
            || (new Query())->from('craft_recipe_tool')->where(['item_id' => $item['id']])->exists($this->db);
    }
    public function units(int $inventory): array
    {
        return (new Query())->from('craft_equipment_instance')->where(['inventory_id' => $inventory, 'status' => 'active'])->orderBy(['id' => SORT_ASC])->all($this->db);
    }
    public function create(array $row, array $item, int $quantity): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Equipment writes require a transaction.');
        if (!$this->tracked($item)) return;
        $ordinal = (int)(new Query())->from('craft_equipment_instance')->where(['origin_inventory_id' => $row['id']])->max('unit_ordinal', $this->db);
        for ($i = 0; $i < $quantity; $i++) $this->db->createCommand()->insert('craft_equipment_instance', [
            'inventory_id' => $row['id'], 'origin_inventory_id' => $row['id'], 'unit_ordinal' => ++$ordinal, 'item_id' => $item['id'],
        ])->execute();
    }
    public function consume(array $ids): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Equipment writes require a transaction.');
        if (!$ids) return;
        $changed = $this->db->createCommand()->update('craft_equipment_instance', ['inventory_id' => null, 'status' => 'consumed', 'revision' => new Expression('[[revision]]+1')], ['id' => $ids, 'status' => 'active'])->execute();
        if ($changed !== count($ids)) throw new ConflictHttpException('Экземпляры предмета изменились.');
    }
    public function move(array $ids, int $inventory): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Equipment writes require a transaction.');
        if ($ids && $this->db->createCommand()->update('craft_equipment_instance', ['inventory_id' => $inventory, 'revision' => new Expression('[[revision]]+1')], ['id' => $ids, 'status' => 'active'])->execute() !== count($ids)) throw new ConflictHttpException('Экземпляры предмета изменились.');
    }
    /** Maintenance only: transfer the old shared wear to exactly one deterministic unit. */
    public function backfill(int $user): array
    {
        $rows = (new Query())->from('craft_inventory')->where(['user_id' => $user])->andWhere(['>', 'item_quantity', 0])->orderBy(['id' => SORT_ASC])->all($this->db);
        $first = []; $count = 0;
        foreach ($rows as $row) {
            $item = (new Query())->from('craft_item')->where(['id' => $row['item_id']])->one($this->db);
            if (!$item || !$this->tracked($item)) continue;
            $units = $this->units((int)$row['id']);
            if (!$units) { $this->create($row, $item, (int)$row['item_quantity']); $units = $this->units((int)$row['id']); }
            if (count($units) !== (int)$row['item_quantity']) throw new \RuntimeException('Equipment backfill snapshot changed; inventory=' . $row['id']);
            foreach ($units as $unit) if ((int)$unit['item_id'] !== (int)$row['item_id']) throw new \RuntimeException('Equipment identity mismatch.');
            $count += count($units);
            if (!isset($first[$item['id']])) $first[$item['id']] = $units[0];
        }
        $mapped = 0;
        foreach ((new Query())->from('craft_tool_wear')->where(['user_id' => $user])->all($this->db) as $wear) {
            $value = (int)$wear['wear']; $item = (int)$wear['item_id'];
            if ($value < 0 || $value >= 100 || ($value && !isset($first[$item]))) throw new \RuntimeException('Legacy wear needs an explicit migration decision; item=' . $item);
            $where = ['user_id' => $user, 'item_id' => $item];
            $old = (new Query())->from('craft_wear_assignment')->where($where)->one($this->db);
            if ($old) {
                if ((int)$old['wear'] !== $value || ($old['instance_id'] === null ? null : (int)$old['instance_id']) !== (isset($first[$item]) ? (int)$first[$item]['id'] : null)) throw new \RuntimeException('Legacy wear changed after backfill.');
                continue;
            }
            $instance = isset($first[$item]) ? (int)$first[$item]['id'] : null;
            if ($instance !== null && $value) $this->db->createCommand()->update('craft_equipment_instance', ['durability' => 100 - $value], ['id' => $instance])->execute();
            $this->db->createCommand()->insert('craft_wear_assignment', $where + ['instance_id' => $instance, 'wear' => $value, 'applied_at' => time()])->execute();
            $mapped += $value;
        }
        return ['units' => $count, 'wear_transferred' => $mapped];
    }
}
