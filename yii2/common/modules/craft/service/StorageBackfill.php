<?php
namespace common\modules\craft\service;

use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Query;

/** Explicit maintenance command, not an HTTP read side effect. */
class StorageBackfill
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function storage(string $key, string $kind, int $owner, int $capacity, ?int $container = null): int
    {
        $existing = (new Query())->from('craft_storage')->where(['identity_key' => $key])->one($this->db);
        if ($existing) return (int)$existing['id'];
        $this->db->createCommand()->insert('craft_storage', ['identity_key' => $key, 'kind' => $kind, 'owner_user_id' => $owner, 'capacity' => $capacity, 'container_inventory_id' => $container])->execute();
        return (int)$this->db->getLastInsertID();
    }
    public function owner(int $user): array
    {
        return $this->db->transaction(function () use ($user) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]);
            // Migration includes blocked/inactive users; gameplay authorization must not filter their property.
            if (!$locks->row('user', ['id' => $user])) throw new \RuntimeException('Inventory owner is missing.');
            $locks->row('persone', ['user_id' => $user]);
            $registry = $locks->row('world_registry', ['id' => 1]);
            if (!$registry || !empty($registry['storage_v2'])) throw new \RuntimeException('Legacy backfill requires storage_v2 to be disabled.');
            (new StorageRollout($this->db))->assertBackfill();
            $baseline = new StorageBaseline($this->db);
            $completed = (new Query())->from('world_storage_baseline')->where(['user_id' => $user])->one($this->db);
            if ($completed) {
                if (!hash_equals($completed['after_hash'], $baseline->migratedHash($user))) throw new \RuntimeException('Previously migrated state changed; baseline is retained.');
                return ['user_id' => $user, 'replayed' => true];
            }
            $snapshot = $baseline->capture($user);
            $store = new CraftStorage($this->db); $inventory = new CraftInventory($store);
            $before = $this->totals($user);
            if ((new Query())->from('craft_inventory')->where(['user_id' => $user])->andWhere(['or', ['<', 'item_quantity', 0], ['and', ['item_id' => null], ['>', 'item_quantity', 0]]])->exists($this->db)) throw new \RuntimeException('Invalid legacy inventory requires a migration decision.');
            // Re-running after the unique index is safe: detach this owner's positions first.
            $this->db->createCommand()->update('craft_inventory', ['storage_id' => null], ['user_id' => $user])->execute();
            $inventory->synchronize($user);
            $backpack = $this->storage('backpack:user:' . $user, 'backpack', $user, CraftInventory::LIMIT);
            $containers = [];
            foreach ($inventory->layout($user) as $row) {
                $item = (new Query())->from('craft_item')->where(['id' => $row['item_id']])->one($this->db);
                if (($item['storage_kind'] ?? '') !== 'chest') continue;
                $container = $inventory->container($user, (int)$row['id']);
                if (!(new Query())->from('craft_container')->where(['id' => $row['id']])->exists($this->db)) $this->db->createCommand()->insert('craft_container', $container)->execute();
                $containers[(int)$row['id']] = $this->storage('chest:item:' . $row['id'], 'chest', $user, (int)$container['capacity'], (int)$row['id']);
            }
            $positions = []; $rows = $store->rows('craft_inventory', ['user_id' => $user]);
            foreach ($rows as $row) {
                $container = (int)($row['container_id'] ?? 0);
                if ($container && !isset($containers[$container])) throw new \RuntimeException('Orphan chest contents require a migration decision; inventory=' . $row['id']);
                $storage = $container ? $containers[$container] : $backpack;
                $position = null;
                if ($row['item_id'] !== null && (int)$row['item_quantity'] > 0) {
                    $position = max(1, (int)$row['slot']);
                    if (!isset($positions[$storage])) $positions[$storage] = [];
                    while (isset($positions[$storage][$position])) $position++;
                    $positions[$storage][$position] = true;
                }
                // Empty rows remain as historical IDs, but no longer occupy unique slot positions.
                $this->db->createCommand()->update('craft_inventory', ['storage_id' => $storage, 'slot' => $position], ['id' => $row['id']])->execute();
            }
            if ($before !== $this->totals($user)) throw new \RuntimeException('Backfill changed item totals.');
            $equipment = (new EquipmentInstances($this->db))->backfill($user);
            foreach ((new Query())->from('world_membership')->where(['user_id' => $user])->all($this->db) as $membership) (new \common\modules\world\service\PlacementProvisioner($this->db))->campsite($user, (int)$membership['starter_site_id']);
            $baseline->compare($snapshot, $baseline->capture($user));
            $encoded = \common\services\game\CanonicalJson::encode($snapshot);
            $this->db->createCommand()->insert('world_storage_baseline', ['user_id' => $user, 'before_json' => $encoded, 'before_hash' => hash('sha256', $encoded), 'after_hash' => $baseline->migratedHash($user), 'migrated_at' => time()])->execute();
            $this->db->createCommand()->update('world_storage_rollout', ['phase' => 'backfill'], ['id' => 1])->execute();
            return ['user_id' => $user, 'backpack_id' => $backpack, 'chests' => count($containers), 'inventory_rows' => count($rows), 'equipment' => $equipment];
        });
    }
    private function totals(int $user): array
    {
        return (new Query())->select(['item_id', 'quantity' => new \yii\db\Expression('SUM([[item_quantity]])')])->from('craft_inventory')->where(['user_id' => $user])->groupBy('item_id')->orderBy(['item_id' => SORT_ASC])->all($this->db);
    }
    public function batch(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $rollout = new StorageRollout($this->db); $rollout->assertBackfill();
        $cursor = 0; $processed = 0;
        // Completion belongs to the same transaction as the owner's items, not a later global cursor.
        $ids = $rollout->pendingUsers($limit);
        foreach ($ids as $id) {
            $this->owner((int)$id); $cursor = (int)$id; $processed++;
        }
        return ['processed' => $processed, 'last_user_id' => $cursor, 'more' => (bool)$rollout->pendingUsers(1), 'activation_allowed' => false];
    }
}
