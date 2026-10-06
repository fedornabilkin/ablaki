<?php
namespace common\modules\world\modules\craft\models\domain;

use common\modules\world\support\CanonicalJson;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Query;

/** Offline rollout only. No HTTP endpoint and no automatic activation on deployment. */
class StorageRollout
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function requireContext(): void
    {
        if (getenv('WORLD_INSTALL') !== 'confirmed-world-install' || getenv('WORLD_BACKFILL_MAINTENANCE') !== '1') throw new \RuntimeException('Use the explicit installation context after draining HTTP/worker/console writers and registrations.');
        if (!$this->db->schema->getTableSchema('world_storage_rollout')) throw new \RuntimeException('Install the rollout schema first.');
    }
    private function locked(): array
    {
        $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $registry = $locks->row('world_registry', ['id' => 1]);
        $rollout = $locks->row('world_storage_rollout', ['id' => 1]);
        if (!$registry || !$rollout || (int)$registry['schema_version'] < 5) throw new \RuntimeException('Complete schema installation first.');
        return [$registry, $rollout];
    }
    public function begin(): array
    {
        $this->requireContext();
        return $this->db->transaction(function () {
            list($registry, $row) = $this->locked();
            if ($row['phase'] !== 'idle') return ['phase' => $row['phase'], 'resumed' => true];
            if ($registry['storage_v2']) throw new \RuntimeException('The inventory is already canonical.');
            if ((new Query())->from('world_storage_baseline')->exists($this->db) || (new Query())->from('craft_equipment_instance')->exists($this->db) || (new Query())->from('craft_inventory')->where(['not', ['storage_id' => null]])->exists($this->db)) throw new \RuntimeException('Untracked earlier backfill detected. Restore the pre-backfill database copy; do not invent an original baseline from migrated data.');
            // A MyISAM participant would make the per-owner snapshot/transfer non-atomic.
            if ($this->db->driverName === 'mysql') {
                foreach (['craft_meta', 'user', 'persone', 'craft_inventory', 'craft_container', 'craft_capacity', 'craft_storage', 'craft_equipment_instance', 'craft_wear_assignment', 'world_storage_rollout', 'world_storage_baseline', 'world_slot', 'world_registry'] as $table) {
                    $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
                    if (strtolower((string)$engine) !== 'innodb') throw new \RuntimeException('Transactional engine required: ' . $table);
                }
            }
            $this->db->createCommand()->update('world_storage_rollout', ['phase' => 'frozen', 'catalog_hash' => (new StorageBaseline($this->db))->catalogHash(), 'started_at' => time()], ['id' => 1])->execute();
            return ['phase' => 'frozen', 'resumed' => false];
        });
    }
    public function cancelBeforeBackfill(): array
    {
        $this->requireContext();
        return $this->db->transaction(function () {
            list($registry, $row) = $this->locked();
            if ($registry['storage_v2'] || !in_array($row['phase'], ['idle', 'frozen'], true) || (new Query())->from('world_storage_baseline')->exists($this->db)) throw new \RuntimeException('Cannot release maintenance after any owner was migrated. Resume the rollout or restore the complete database backup while stopped.');
            $this->db->createCommand()->update('world_storage_rollout', ['phase' => 'idle', 'catalog_hash' => null, 'started_at' => null], ['id' => 1])->execute();
            return ['phase' => 'idle', 'inventory_changed' => false];
        });
    }
    public function assertBackfill(): array
    {
        $this->requireContext();
        $row = (new Query())->from('world_storage_rollout')->where(['id' => 1])->one($this->db);
        if (!$row || !in_array($row['phase'], ['frozen', 'backfill'], true)) throw new \RuntimeException('Start or resume the frozen rollout before backfill.');
        if (!hash_equals($row['catalog_hash'], (new StorageBaseline($this->db))->catalogHash())) throw new \RuntimeException('Catalog/settings changed during rollout. Restore the original snapshot before continuing.');
        return $row;
    }
    public function pendingUsers(int $limit): array
    {
        return (new Query())->select('u.id')->from(['u' => 'user'])->leftJoin(['b' => 'world_storage_baseline'], '[[b.user_id]]=[[u.id]]')->where(['b.user_id' => null])->orderBy(['u.id' => SORT_ASC])->limit($limit)->column($this->db);
    }
    public function status(): array
    {
        if (!$this->db->schema->getTableSchema('world_storage_rollout')) return ['phase' => 'not_installed', 'activated_at' => null];
        $row = (new Query())->from('world_storage_rollout')->where(['id' => 1])->one($this->db);
        return ['phase' => $row['phase'] ?? 'missing', 'migrated_users' => (int)(new Query())->from('world_storage_baseline')->count('*', $this->db),
            'verified_users' => (int)(new Query())->from('world_storage_baseline')->where(['not', ['verified_at' => null]])->count('*', $this->db), 'has_pending_users' => ($row['phase'] ?? null) !== 'active' && (bool)$this->pendingUsers(1), 'activated_at' => ($row['activated_at'] ?? null) === null ? null : (int)$row['activated_at']];
    }
    public function verify(int $limit = 100): array
    {
        $this->requireContext(); $processed = 0;
        $ids = (new Query())->select('user_id')->from('world_storage_baseline')->where(['verified_at' => null])->orderBy(['user_id' => SORT_ASC])->limit(max(1, min(1000, $limit)))->column($this->db);
        foreach ($ids as $id) {
            $this->db->transaction(function () use ($id) {
                list($registry, $rollout) = $this->locked();
                if ($registry['storage_v2'] || !in_array($rollout['phase'], ['frozen', 'backfill', 'verified'], true)) throw new \RuntimeException('Verification requires a frozen legacy rollout.');
                $this->verifyOwner((int)$id);
                $this->db->createCommand()->update('world_storage_baseline', ['verified_at' => time()], ['user_id' => $id])->execute();
            }); $processed++;
        }
        return $this->status() + ['processed' => $processed];
    }
    private function verifyOwner(int $user): void
    {
        $baseline = new StorageBaseline($this->db); $row = (new Query())->from('world_storage_baseline')->where(['user_id' => $user])->one($this->db);
        if (!$row || !hash_equals($row['before_hash'], hash('sha256', $row['before_json']))) throw new \RuntimeException('Missing or damaged baseline for user ' . $user);
        $before = json_decode($row['before_json'], true, 512, JSON_THROW_ON_ERROR);
        $baseline->compare($before, $baseline->capture($user));
        if (!hash_equals($row['after_hash'], $baseline->migratedHash($user))) throw new \RuntimeException('Migrated state changed for user ' . $user);
    }
    /** DDL is deliberately outside all transactions on MySQL/MariaDB. Freeze remains set on failure. */
    public function prepareIndex(): array
    {
        $this->requireContext();
        if (!StorageMaintenance::frozen($this->db)) throw new \RuntimeException('Index preparation requires the persistent freeze.');
        if ($this->db->getTransaction()) throw new \RuntimeException('Index DDL cannot run inside the rollout transaction.');
        if ($this->pendingUsers(1)) throw new \RuntimeException('Finish every owner backfill first.');
        $report = (new StorageReconciliation($this->db))->report();
        if (!$report['consistent']) throw new \RuntimeException('Storage reconciliation failed; use world-audit/storage.');
        if (!$this->hasIndex()) $this->db->createCommand()->createIndex('ux_craft_inventory_storage_slot', 'craft_inventory', ['storage_id', 'slot'], true)->execute();
        $this->db->schema->refresh(); return ['index_ready' => $this->hasIndex(), 'activated' => false];
    }
    private function hasIndex(): bool
    {
        foreach ($this->db->schema->getTableIndexes('craft_inventory', true) as $index) if ($index->name === 'ux_craft_inventory_storage_slot') {
            if (!$index->isUnique || $index->columnNames !== ['storage_id', 'slot']) throw new \RuntimeException('The named storage index has an unexpected definition.');
            return true;
        }
        return false;
    }
    public function activate(): array
    {
        $this->requireContext();
        return $this->db->transaction(function () {
            list($registry, $rollout) = $this->locked();
            if ($rollout['phase'] === 'active' && $registry['storage_v2']) return ['phase' => 'active', 'replayed' => true];
            if ($registry['storage_v2'] || !in_array($rollout['phase'], ['frozen', 'backfill', 'verified'], true)) throw new \RuntimeException('Activation requires a frozen rollout.');
            if (!hash_equals($rollout['catalog_hash'], (new StorageBaseline($this->db))->catalogHash())) throw new \RuntimeException('Catalog/settings baseline changed.');
            if ($this->pendingUsers(1) || (new Query())->from('world_storage_baseline')->where(['verified_at' => null])->exists($this->db)) throw new \RuntimeException('Finish backfill and verification for every owner.');
            if (!$this->hasIndex()) throw new \RuntimeException('Prepare the unique storage index outside this transaction first.');
            // Never trust an earlier green report: repeat the complete comparison at the switch boundary.
            foreach ((new Query())->select('user_id')->from('world_storage_baseline')->orderBy(['user_id' => SORT_ASC])->each(100, $this->db) as $row) $this->verifyOwner((int)$row['user_id']);
            $report = (new StorageReconciliation($this->db))->report();
            if (!$report['consistent']) throw new \RuntimeException('Storage reconciliation failed at activation.');
            $this->db->createCommand()->update('world_registry', ['storage_v2' => 1], ['id' => 1])->execute();
            $this->db->createCommand()->update('world_storage_rollout', ['phase' => 'active', 'activated_at' => time()], ['id' => 1])->execute();
            return ['phase' => 'active', 'replayed' => false, 'note' => 'Canonical format is permanent. UI access still requires its environment flag.'];
        });
    }
}
