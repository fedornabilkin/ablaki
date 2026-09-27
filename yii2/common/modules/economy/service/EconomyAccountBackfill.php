<?php
namespace common\modules\economy\service;

use common\modules\craft\service\StorageMaintenance;
use common\modules\economy\value\Money;
use common\modules\world\service\WorldFlags;
use common\services\game\CanonicalJson;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Query;

/** Offline zero-account provisioning. Never imports personal credit or changes an existing balance. */
class EconomyAccountBackfill
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    private function context(): void
    {
        if (getenv('WORLD_INSTALL') !== 'confirmed-world-install' || getenv('WORLD_ECONOMY_BACKFILL') !== 'confirmed-zero-accounts') throw new \RuntimeException('Set WORLD_INSTALL and WORLD_ECONOMY_BACKFILL=confirmed-zero-accounts after pausing world writers.');
        if (!$this->db->schema->getTableSchema('economy_account_backfill_run')) throw new \RuntimeException('Install the account backfill schema first.');
        if (!in_array($this->db->driverName, ['mysql', 'pgsql'], true)) throw new \RuntimeException('Backfill requires MySQL/MariaDB or PostgreSQL.');
        if ($this->db->driverName === 'mysql') foreach (['craft_meta', 'world_registry', 'world_node', 'economy_subject', 'economy_account', 'economy_parent_history', 'economy_parent_rule', 'game_operation', 'economy_account_backfill_run', 'economy_account_backfill_item'] as $table) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
            if (strtolower((string)$engine) !== 'innodb') throw new \RuntimeException('Transactional engine required: ' . $table);
        }
    }
    private function lock(): void
    {
        $locks = new Locks($this->db);
        if (!$locks->row('craft_meta', ['id' => 1])) throw new \RuntimeException('Catalog registry missing.');
        $registry = $locks->row('world_registry', ['id' => 1]);
        if (!$registry || (int)$registry['schema_version'] < WorldFlags::SCHEMA_VERSION) throw new \RuntimeException('Complete schema installation first.');
        if ($registry['world_write'] || $registry['economy_tick']) throw new \RuntimeException('Disable persistent world_write and economy_tick before zero-account backfill.');
        StorageMaintenance::writable($this->db); WalletMaintenance::writable($this->db);
    }
    private function world(int $id): array
    {
        $row = $this->one('world_node', ['id' => $id, 'node_type' => 'WORLD', 'status' => 'active']);
        if (!$row || $row['parent_id'] !== null || (int)$row['root_id'] !== $id) throw new \RuntimeException('An active world root is required.');
        return $row;
    }
    /** Current physical chain selects eligibility only; existing financial parents are retained. */
    private function chain(array $node): array
    {
        $chain = []; $cursor = $node; $inactive = false;
        while ($cursor) {
            $id = (int)$cursor['id'];
            if (isset($chain[$id]) || count($chain) >= 33 || (int)$cursor['root_id'] !== (int)$node['root_id']) throw new \RuntimeException('Invalid physical ancestry at node ' . $node['id']);
            $chain[$id] = $cursor; $inactive = $inactive || $cursor['status'] !== 'active';
            if ($cursor['parent_id'] === null) break;
            $cursor = $this->one('world_node', ['id' => $cursor['parent_id']]);
            if (!$cursor) throw new \RuntimeException('Missing physical parent at node ' . $node['id']);
        }
        $top = end($chain);
        if (!$top || $top['node_type'] !== 'WORLD' || (int)$top['id'] !== (int)$node['root_id']) throw new \RuntimeException('Invalid world root at node ' . $node['id']);
        if ((new Query())->from('world_shelter_deployment')->where(['node_id' => array_keys($chain)])->exists($this->db)) return ['nodes' => [], 'skip' => 'shelter'];
        return ['nodes' => $chain, 'skip' => $inactive ? 'inactive_ancestry' : null];
    }
    private function snapshot(array $ids): array
    {
        $subjects = $ids ? (new Query())->from('economy_subject')->where(['node_id' => $ids])->orderBy(['id' => SORT_ASC])->indexBy('id')->all($this->db) : [];
        foreach ($subjects as $subject) if ($subject['actor_id'] !== null || $subject['asset_instance_id'] !== null) throw new \RuntimeException('Mixed economy subject identity: ' . $subject['id']);
        $subjectIds = array_keys($subjects);
        $accounts = $subjectIds ? (new Query())->from('economy_account')->where(['subject_id' => $subjectIds])->orderBy(['id' => SORT_ASC])->indexBy('id')->all($this->db) : [];
        $rules = $subjectIds ? (new Query())->from('economy_parent_rule')->where(['subject_id' => $subjectIds])->orderBy(['subject_id' => SORT_ASC])->indexBy('subject_id')->all($this->db) : [];
        $history = $rules ? (new Query())->from('economy_parent_history')->where(['id' => array_column($rules, 'history_id')])->orderBy(['id' => SORT_ASC])->indexBy('id')->all($this->db) : [];
        foreach ($accounts as $account) {
            $amount = Money::parse((string)$account['amount']); $reserved = Money::parse((string)$account['reserved']);
            if ($amount->isNegative() || $reserved->isNegative() || $reserved->compare($amount) > 0) throw new \RuntimeException('Invalid account amount/reserve: ' . $account['id']);
        }
        $unlinked = array_values(array_diff($subjectIds, array_keys($rules)));
        if ($unlinked && (new Query())->from('economy_parent_history')->where(['subject_id' => $unlinked])->exists($this->db)) throw new \RuntimeException('History without current parent pointer. Restore the original pointer before backfill.');
        return compact('subjects', 'accounts', 'rules', 'history');
    }
    private function validateParents(array $subjects, int $world): void
    {
        foreach ($subjects as $subject) {
            $seen = []; $cursor = $subject;
            while ($cursor) {
                $id = (int)$cursor['id'];
                if (isset($seen[$id]) || count($seen) >= 33 || $cursor['node_id'] === null || $cursor['actor_id'] !== null || $cursor['asset_instance_id'] !== null) throw new \RuntimeException('Invalid financial ancestry at subject ' . $subject['id']);
                $seen[$id] = true;
                $node = $this->one('world_node', ['id' => $cursor['node_id']]);
                $rule = $this->one('economy_parent_rule', ['subject_id' => $id]);
                $history = $rule ? $this->one('economy_parent_history', ['id' => $rule['history_id'], 'subject_id' => $id, 'revision' => $rule['revision'], 'effective_to' => null]) : null;
                if (!$node || (int)$node['root_id'] !== $world || !$history) throw new \RuntimeException('Missing or mismatched financial parent: ' . $id);
                if ($history['parent_subject_id'] === null) {
                    if ($node['node_type'] !== 'WORLD' || (int)$node['id'] !== $world || $history['status'] !== 'root') throw new \RuntimeException('Financial chain must end at its world root.');
                    break;
                }
                if ($node['node_type'] === 'WORLD' || $history['status'] === 'root') throw new \RuntimeException('World root cannot have a financial parent.');
                $cursor = $this->one('economy_subject', ['id' => $history['parent_subject_id']]);
                if (!$cursor) throw new \RuntimeException('Missing financial parent subject.');
            }
        }
    }
    public function begin(int $world): array
    {
        $this->context();
        return $this->db->transaction(function () use ($world) {
            $this->lock(); $this->world($world);
            $existing = $this->one('economy_account_backfill_run', ['active_world_id' => $world]);
            if ($existing) return $this->status((int)$existing['id']) + ['resumed' => true];
            $upper = (int)(new Query())->from('world_node')->where(['root_id' => $world])->max('id', $this->db);
            $this->db->createCommand()->insert('economy_account_backfill_run', ['world_id' => $world, 'active_world_id' => $world, 'upper_node_id' => $upper, 'status' => 'running', 'started_at' => time(), 'updated_at' => time()])->execute();
            return $this->status((int)$this->db->getLastInsertID()) + ['resumed' => false];
        });
    }
    public function status(int $run): array
    {
        $row = $this->one('economy_account_backfill_run', ['id' => $run]);
        if (!$row) throw new \RuntimeException('Backfill run not found.');
        foreach (['id', 'world_id', 'upper_node_id', 'cursor_id', 'processed_count', 'created_accounts', 'skipped_count', 'started_at', 'updated_at', 'completed_at', 'active_world_id'] as $field) $row[$field] = $row[$field] === null ? null : (int)$row[$field];
        $row['covers_nodes_created_after_start'] = false; $row['activates_gameplay'] = false;
        return $row;
    }
    public function batch(int $run, int $limit = 50): array
    {
        $this->context(); $processed = 0;
        for ($i = 0; $i < max(1, min(200, $limit)); $i++) {
            $advanced = $this->db->transaction(function () use ($run) {
                $this->lock(); $row = (new Locks($this->db))->row('economy_account_backfill_run', ['id' => $run]);
                if (!$row) throw new \RuntimeException('Backfill run not found.');
                if ($row['status'] === 'completed') return false;
                if ($row['status'] !== 'running' || (int)$row['active_world_id'] !== (int)$row['world_id']) throw new \RuntimeException('Invalid backfill state.');
                $this->world((int)$row['world_id']);
                $node = (new Query())->from('world_node')->where(['root_id' => $row['world_id']])->andWhere(['>', 'id', $row['cursor_id']])->andWhere(['<=', 'id', $row['upper_node_id']])->orderBy(['id' => SORT_ASC])->one($this->db);
                if (!$node) {
                    if ($this->db->createCommand()->update('economy_account_backfill_run', ['status' => 'completed', 'active_world_id' => null, 'updated_at' => time(), 'completed_at' => time()], ['id' => $run, 'status' => 'running'])->execute() !== 1) throw new \RuntimeException('Backfill completion failed.');
                    return false;
                }
                $chain = $this->chain($node); $before = []; $after = []; $operation = null; $created = 0;
                if ($chain['skip'] === null) {
                    $before = $this->snapshot(array_keys($chain['nodes']));
                    $operation = bin2hex(random_bytes(16));
                    $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'economy.accounts.backfill', 'created_at' => time()])->execute();
                    (new EconomyHierarchy($this->db))->provision((int)$node['id'], $operation);
                    $after = $this->snapshot(array_keys($chain['nodes']));
                    $this->validateParents($after['subjects'], (int)$row['world_id']);
                    foreach (['subjects', 'accounts', 'rules', 'history'] as $kind) foreach ($before[$kind] as $id => $value) {
                        if (!isset($after[$kind][$id]) || CanonicalJson::encode($value) !== CanonicalJson::encode($after[$kind][$id])) throw new \RuntimeException('Backfill changed existing ' . $kind . ': ' . $id);
                    }
                    foreach ($after['accounts'] as $id => $account) if (!isset($before['accounts'][$id])) {
                        if (!Money::parse((string)$account['amount'])->isZero() || !Money::parse((string)$account['reserved'])->isZero() || !in_array($account['role'], ['budget', 'treasury'], true) || $account['currency'] !== 'Cr') throw new \RuntimeException('New account must be zero budget/treasury in Cr.');
                        $created++;
                    }
                }
                $this->db->createCommand()->insert('economy_account_backfill_item', ['run_id' => $run, 'node_id' => $node['id'], 'operation_id' => $operation, 'outcome' => $chain['skip'] ?? ($created ? 'provisioned' : 'already_present'), 'created_accounts' => $created, 'before_json' => CanonicalJson::encode($before), 'after_json' => CanonicalJson::encode($after), 'created_at' => time()])->execute();
                if ($this->db->createCommand()->update('economy_account_backfill_run', ['cursor_id' => $node['id'], 'processed_count' => (int)$row['processed_count'] + 1, 'created_accounts' => (int)$row['created_accounts'] + $created, 'skipped_count' => (int)$row['skipped_count'] + ($chain['skip'] === null ? 0 : 1), 'updated_at' => time()], ['id' => $run, 'cursor_id' => $row['cursor_id']])->execute() !== 1) throw new \RuntimeException('Backfill checkpoint update failed.');
                return true;
            });
            if (!$advanced) break; $processed++;
        }
        return $this->status($run) + ['processed_in_batch' => $processed];
    }
    /** Bounded diagnostic page; consistency applies only to this page, never a whole-world certificate. */
    public function report(int $world, int $after = 0, int $limit = 100): array
    {
        if ($after < 0) throw new \InvalidArgumentException('Cursor must be non-negative.');
        return (new FinanceReadSnapshot($this->db))->run(function () use ($world, $after, $limit) {
            $this->world($world); $items = []; $limit = max(1, min(200, $limit));
            $rows = (new Query())->from('world_node')->where(['root_id' => $world])->andWhere(['>', 'id', $after])->orderBy(['id' => SORT_ASC])->limit($limit + 1)->all($this->db);
            $more = count($rows) > $limit;
            foreach (array_slice($rows, 0, $limit) as $node) {
                $entry = ['node_id' => (int)$node['id'], 'status' => 'ready', 'missing_roles' => [], 'issues' => []];
                try {
                    $chain = $this->chain($node);
                    if ($chain['skip'] !== null) $entry['status'] = $chain['skip'];
                    else {
                        $snapshot = $this->snapshot([(int)$node['id']]);
                        $roles = []; foreach ($snapshot['accounts'] as $account) if ($account['currency'] === 'Cr') $roles[] = $account['role'];
                        $entry['missing_roles'] = array_values(array_diff(['budget', 'treasury'], $roles));
                        if (!$snapshot['subjects'] || $entry['missing_roles'] || !$snapshot['rules']) $entry['status'] = 'needs_backfill';
                        if ($snapshot['rules']) $this->validateParents($snapshot['subjects'], $world);
                    }
                } catch (\RuntimeException $e) { $entry['status'] = 'invalid'; $entry['issues'][] = $e->getMessage(); }
                catch (\InvalidArgumentException $e) { $entry['status'] = 'invalid'; $entry['issues'][] = 'Invalid monetary format.'; }
                $items[] = $entry;
            }
            return ['read_only' => true, 'world_id' => $world, 'items' => $items, 'has_more' => $more, 'next_after_node' => $items ? $items[count($items) - 1]['node_id'] : $after, 'scope' => 'page', 'generated_at' => time()];
        });
    }
}
