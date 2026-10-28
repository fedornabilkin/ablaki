<?php
namespace common\modules\world\support;

use yii\db\Connection;
use yii\db\Query;

/** Jobs are delivered at least once. The live lease is locked through handler commit. */
class JobQueue
{
    private $db;
    private $locks;
    public function __construct(Connection $db) { $this->db = $db; $this->locks = new Locks($db); }
    public function enqueue(string $type, string $key, array $payload, ?int $owner = null, ?int $at = null): int
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Enqueue must share the domain transaction and registry lock.');
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $type) || strlen($key) > 100 || $key === '') throw new \InvalidArgumentException('Invalid job identity.');
        $json = CanonicalJson::encode($payload);
        $old = (new Query())->from('game_job')->where(['type' => $type, 'business_key' => $key])->one($this->db);
        if ($old) {
            if ($old['payload_json'] !== $json || ($old['owner_user_id'] === null ? null : (int)$old['owner_user_id']) !== $owner) throw new \LogicException('Job business key reused.');
            return (int)$old['id'];
        }
        $this->db->createCommand()->insert('game_job', ['type' => $type, 'business_key' => $key, 'owner_user_id' => $owner, 'payload_json' => $json, 'available_at' => $at ?? time(), 'created_at' => time()])->execute();
        return (int)$this->db->getLastInsertID();
    }
    public function claim(array $types, int $leaseSeconds = 120): ?array
    {
        if (!$types) return null;
        return $this->db->transaction(function () use ($types, $leaseSeconds) {
            $this->locks->row('world_registry', ['id' => 1]);
            if (\common\modules\world\modules\craft\models\domain\StorageMaintenance::frozen($this->db) || \common\modules\world\modules\economy\models\domain\WalletMaintenance::frozen($this->db)) return null;
            $job = (new Query())->from('game_job')->where(['type' => $types])->andWhere(['or',
                ['and', ['status' => 'pending'], ['<=', 'available_at', time()]], ['and', ['status' => 'running'], ['<=', 'lease_until', time()]]])
                ->orderBy(['available_at' => SORT_ASC, 'id' => SORT_ASC])->one($this->db);
            if (!$job) return null;
            $job = $this->locks->row('game_job', ['id' => $job['id']]);
            $values = ['status' => 'running', 'lease_until' => time() + max(10, min(600, $leaseSeconds)), 'fencing_token' => (int)$job['fencing_token'] + 1, 'attempts' => (int)$job['attempts'] + 1];
            $this->db->createCommand()->update('game_job', $values, ['id' => $job['id']])->execute();
            return array_merge($job, $values);
        });
    }
    public function perform(array $claim, callable $handler): bool
    {
        try {
            return $this->db->transaction(function () use ($claim, $handler) {
                $this->locks->row('craft_meta', ['id' => 1]);
                if ($claim['owner_user_id'] !== null) $this->locks->owners([(int)$claim['owner_user_id']]);
                $this->locks->row('world_registry', ['id' => 1]);
                $job = $this->locks->row('game_job', ['id' => $claim['id']]);
                if (!$job || $job['status'] !== 'running' || (int)$job['fencing_token'] !== (int)$claim['fencing_token'] || (int)$job['lease_until'] <= time()) return false;
                if (\common\modules\world\modules\craft\models\domain\StorageMaintenance::frozen($this->db) || \common\modules\world\modules\economy\models\domain\WalletMaintenance::frozen($this->db)) {
                    $this->db->createCommand()->update('game_job', ['status' => 'pending', 'lease_until' => null, 'available_at' => time() + 60, 'attempts' => max(0, (int)$job['attempts'] - 1)], ['id' => $job['id']])->execute();
                    return false;
                }
                $handler(json_decode($job['payload_json'], true, 512, JSON_THROW_ON_ERROR), $job);
                // No other worker can advance the fence while this transaction holds the row lock.
                $this->db->createCommand()->update('game_job', ['status' => 'done', 'finished_at' => time(), 'lease_until' => null, 'last_error_code' => null], ['id' => $job['id'], 'fencing_token' => $job['fencing_token']])->execute();
                return true;
            });
        } catch (\Throwable $error) {
            $this->fail($claim, $error instanceof GameError ? $error->reason : 'HANDLER_FAILED');
            \Yii::warning(['job_id' => (int)$claim['id'], 'exception' => get_class($error)], 'world.jobs');
            return false;
        }
    }
    private function fail(array $claim, string $reason): void
    {
        $this->db->transaction(function () use ($claim, $reason) {
            $job = $this->locks->row('game_job', ['id' => $claim['id']]);
            if (!$job || $job['status'] !== 'running' || (int)$job['fencing_token'] !== (int)$claim['fencing_token']) return;
            $paused = in_array($reason, ['FEATURE_DISABLED', 'WALLET_MAINTENANCE', 'STORAGE_MAINTENANCE'], true);
            $dead = !$paused && (int)$job['attempts'] >= 8;
            $this->db->createCommand()->update('game_job', ['status' => $dead ? 'dead' : 'pending', 'lease_until' => null,
                'attempts' => $paused ? max(0, (int)$job['attempts'] - 1) : (int)$job['attempts'],
                'available_at' => time() + min(3600, 5 * (2 ** min(9, (int)$job['attempts']))), 'last_error_code' => substr($reason, 0, 80)], ['id' => $job['id']])->execute();
        });
    }
}
