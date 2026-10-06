<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\StorageBackfill;
use common\modules\world\modules\craft\models\domain\StorageRollout;
use common\modules\world\modules\economy\models\domain\EconomyAccountBackfill;
use common\modules\world\modules\economy\models\domain\WalletRollout;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Query;

/** Called only by the drained test deployment. Reuses the resumable production-grade rollouts. */
class TestWorldSetup
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public static function requireContext(): void
    {
        if (getenv('WORLD_TEST_SETUP') !== 'confirmed-test-checkout' || getenv('WORLD_TEST_MODE') !== '1' || getenv('WORLD_INSTALL') !== 'confirmed-world-install') throw new \RuntimeException('Test setup requires the dedicated test deployment context.');
    }
    private function progress(string $step, array $data = []): void
    {
        echo json_encode(['step' => $step] + $data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
    public function rollouts(): void
    {
        self::requireContext();
        putenv('WORLD_BACKFILL_MAINTENANCE=1'); putenv('WORLD_WALLET_MAINTENANCE=1');
        $storage = new StorageRollout($this->db);
        if ($storage->status()['phase'] !== 'active') {
            $storage->begin();
            while ($storage->status()['has_pending_users']) {
                $batch = (new StorageBackfill($this->db))->batch(100);
                $this->progress('storage-backfill', $batch);
            }
            do {
                $state = $storage->verify(100);
                $this->progress('storage-verify', $state);
            } while ($state['verified_users'] < $state['migrated_users']);
            $storage->prepareIndex(); $storage->activate();
        }
        $this->progress('storage-active');
        $wallet = new WalletRollout($this->db); $state = $wallet->status();
        if (!$state['wallet_ready']) {
            $state = $wallet->begin();
            while (in_array($state['rollout']['phase'], ['frozen', 'snapshotted'], true)) {
                if ($state['rollout']['phase'] === 'snapshotted') break;
                $state = $wallet->snapshot(2000);
                $this->progress('wallet-snapshot', ['phase' => $state['rollout']['phase']]);
            }
            if (in_array($state['rollout']['phase'], ['snapshotted', 'converting'], true)) $state = $wallet->convert();
            if ($state['rollout']['phase'] === 'converted') $state = $wallet->verify();
            $wallet->activate();
        }
        $this->progress('wallet-active');
    }
    public function activate(WorldFlags $flags): array
    {
        self::requireContext();
        $this->db->transaction(function () {
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            $this->db->createCommand()->update('world_registry', ['world_write' => 0, 'economy_tick' => 0], ['id' => 1])->execute();
        });
        $this->rollouts();
        putenv('WORLD_ECONOMY_BACKFILL=confirmed-zero-accounts');
        $backfill = new EconomyAccountBackfill($this->db);
        foreach ((new Query())->select('id')->from('world_node')->where(['node_type' => 'WORLD', 'status' => 'active'])->column($this->db) as $world) {
            $run = $backfill->begin((int)$world);
            do { $run = $backfill->batch($run['id'], 100); $this->progress('accounts-backfill', ['run' => $run['id'], 'status' => $run['status'], 'processed' => $run['processed_count']]); } while ($run['status'] !== 'completed');
        }
        (new TestWorldDefaults($this->db, $flags))->seed();
        $this->db->transaction(function () {
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            $this->db->createCommand()->update('world_registry', ['world_read' => 1, 'world_write' => 1, 'economy_tick' => 1], ['id' => 1])->execute();
        });
        $world = (int)(new Query())->select('active_world_id')->from('world_registry')->where(['id' => 1])->scalar($this->db);
        $nights = new WorldNights($this->db, $flags);
        if (!$nights->policy($world)) $nights->activate($world, ['day_seconds' => 3600, 'night_offset' => 2700, 'night_seconds' => 900, 'max_severity' => 3, 'recovery_nights' => 1, 'mild_efficiency_bps' => 9000, 'severe_efficiency_bps' => 7500]);
        return ['ready' => true, 'schema_version' => WorldFlags::SCHEMA_VERSION, 'storage' => 'active', 'wallet' => 'active', 'world_id' => $world, 'flags' => $flags->capabilities()];
    }
}
