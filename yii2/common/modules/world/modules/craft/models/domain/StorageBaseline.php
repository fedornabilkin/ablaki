<?php
namespace common\modules\world\modules\craft\models\domain;

use common\modules\world\support\CanonicalJson;
use yii\db\Connection;
use yii\db\Query;

/** No secrets: inventory identity, chest contents, wear, capacity, XP, credit and accepted command digests. */
class StorageBaseline
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function rows(string $table, array $where = []): array
    {
        $keys = $this->db->schema->getTableSchema($table)->primaryKey;
        return (new Query())->from($table)->where($where)->orderBy(array_fill_keys($keys, SORT_ASC))->all($this->db);
    }
    /** PDO may return different numeric scalar types; normalize leaves without float arithmetic. */
    private function normalize($value)
    {
        if (is_array($value)) {
            // Stored CanonicalJson sorts object keys; PDO SELECT * follows column order.
            // Keep list/row order and scalar values strict, but compare object keys canonically.
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
            return array_map(function ($entry) { return $this->normalize($entry); }, $value);
        }
        return $value === null ? null : (string)$value;
    }
    public function hash(array $value): string { return hash('sha256', CanonicalJson::encode($this->normalize($value))); }
    public function catalogHash(): string
    {
        $all = [];
        foreach (['craft_meta', 'craft_item', 'craft_category', 'craft_recipe', 'craft_recipe_item', 'craft_recipe_tool', 'craft_station', 'craft_dependency'] as $table) $all[$table] = $this->rows($table);
        return $this->hash($all);
    }
    public function migratedHash(int $user): string
    {
        $snapshot = $this->capture($user);
        $snapshot['mapped_inventory'] = $this->rows('craft_inventory', ['user_id' => $user]);
        $snapshot['storages'] = $this->rows('craft_storage', ['owner_user_id' => $user]);
        $ids = array_column($snapshot['mapped_inventory'], 'id');
        $snapshot['instances'] = $ids ? $this->rows('craft_equipment_instance', ['or', ['inventory_id' => $ids], ['origin_inventory_id' => $ids]]) : [];
        $snapshot['wear_assignment'] = $this->rows('craft_wear_assignment', ['user_id' => $user]);
        $unitIds = array_column($snapshot['instances'], 'id');
        $snapshot['exposure'] = $unitIds ? $this->rows('craft_equipment_exposure', ['instance_id' => $unitIds]) : [];
        $storageIds = array_column($snapshot['storages'], 'id');
        $snapshot['slots'] = $storageIds ? $this->rows('world_slot', ['storage_id' => $storageIds]) : [];
        return $this->hash($snapshot);
    }
    public function capture(int $user): array
    {
        $where = ['user_id' => $user]; $rows = [];
        foreach ($this->rows('craft_inventory', $where) as $row) $rows[] = ['id' => $row['id'], 'item_id' => $row['item_id'], 'quantity' => (int)$row['item_quantity'], 'container_id' => $row['container_id']];
        $snapshot = ['inventory' => $rows, 'credit' => (new Query())->select('credit')->from('persone')->where($where)->scalar($this->db)];
        foreach (['craft_container', 'craft_capacity', 'craft_slot_lease', 'craft_tool_wear', 'craft_skill', 'craft_known'] as $table) $snapshot[$table] = $this->rows($table, $where);
        foreach (['craft_command', 'craft_history', 'craft_event'] as $table) {
            $digest = hash_init('sha256'); $count = 0;
            $keys = $this->db->schema->getTableSchema($table)->primaryKey;
            foreach ((new Query())->from($table)->where($where)->orderBy(array_fill_keys($keys, SORT_ASC))->each(100, $this->db) as $row) { hash_update($digest, CanonicalJson::encode($this->normalize($row)) . "\n"); $count++; }
            $snapshot[$table] = ['count' => $count, 'hash' => hash_final($digest)];
        }
        return $this->normalize($snapshot);
    }
    /** Known, narrowly defined normalization only; no deletion or arbitrary item substitution. */
    public function compare(array $before, array $after): void
    {
        $before = $this->normalize($before); $after = $this->normalize($after);
        foreach (['credit', 'craft_slot_lease', 'craft_tool_wear', 'craft_skill', 'craft_known', 'craft_command', 'craft_history', 'craft_event'] as $field) {
            if ($before[$field] !== $after[$field]) throw new \RuntimeException('Baseline mismatch: ' . $field);
        }
        $capacityBefore = $before['craft_capacity']; $capacityAfter = $after['craft_capacity'];
        if ($capacityBefore) {
            if ($capacityBefore !== $capacityAfter) throw new \RuntimeException('Baseline mismatch: permanent capacity');
        } elseif ($capacityAfter && (count($capacityAfter) !== 1 || (int)$capacityAfter[0]['permanent_slots'] !== CraftInventory::BASE)) throw new \RuntimeException('Unexpected initial capacity');
        $items = (new Query())->from('craft_item')->indexBy('id')->all($this->db);
        $old = array_column($before['inventory'], null, 'id'); $new = array_column($after['inventory'], null, 'id');
        $split = []; $totalsBefore = []; $totalsAfter = [];
        foreach ($old as $id => $row) {
            if (!isset($new[$id])) throw new \RuntimeException('An original inventory ID disappeared');
            $expected = $row;
            $chest = ($items[$row['item_id']]['storage_kind'] ?? '') === 'chest';
            if ($chest && !$capacityBefore && $row['container_id'] === null && (int)$row['quantity'] > 1) { $expected['quantity'] = '1'; $split[$row['item_id']] = ($split[$row['item_id']] ?? 0) + (int)$row['quantity'] - 1; }
            if ($new[$id] !== $expected) throw new \RuntimeException('An original inventory row changed unexpectedly: ' . $id);
            $key = ($row['item_id'] ?? 'null') . ':' . ($row['container_id'] ?? 'backpack');
            $totalsBefore[$key] = ($totalsBefore[$key] ?? 0) + (int)$row['quantity'];
        }
        foreach ($new as $id => $row) {
            if (!isset($old[$id])) {
                if ($row['container_id'] !== null || (int)$row['quantity'] !== 1 || empty($split[$row['item_id']])) throw new \RuntimeException('Unexpected new inventory row');
                $split[$row['item_id']]--;
            }
            $key = ($row['item_id'] ?? 'null') . ':' . ($row['container_id'] ?? 'backpack');
            $totalsAfter[$key] = ($totalsAfter[$key] ?? 0) + (int)$row['quantity'];
        }
        ksort($totalsBefore); ksort($totalsAfter);
        if ($totalsBefore !== $totalsAfter || array_sum($split)) throw new \RuntimeException('Item totals or chest contents changed');
        $containers = array_column($after['craft_container'], null, 'id');
        foreach ($before['craft_container'] as $row) if (($containers[$row['id']] ?? null) !== $row) throw new \RuntimeException('Existing chest durability/capacity changed');
        $existing = array_column($before['craft_container'], null, 'id'); $defaults = (new CraftInventory(new CraftStorage($this->db)))->settings();
        foreach ($containers as $id => $row) if (!isset($existing[$id])) {
            $inventory = $new[$id] ?? null;
            if (!$inventory || ($items[$inventory['item_id']]['storage_kind'] ?? '') !== 'chest' || (int)$inventory['quantity'] !== 1 || (int)$row['capacity'] !== $defaults['chest_slots'] || (int)$row['durability'] !== $defaults['chest_durability'] || (int)$row['max_durability'] !== $defaults['chest_durability']) throw new \RuntimeException('Unexpected lazy chest materialization');
        }
    }
}
