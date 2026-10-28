<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Offline, resumable preparation and explicit activation. No automatic rounding or reverse DDL. */
class WalletRollout
{
    private const COLUMNS = ['persone.credit', 'history_balance.credit', 'history_balance.credit_up'];
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }

    public function status(): array
    {
        $this->requireSchema();
        $run = $this->run();
        return ['rollout' => $run, 'columns' => $run ? (new Query())->from('economy_wallet_column')
            ->select(['column_key', 'original_type', 'phase', 'row_count', 'last_source_id', 'raw_hash', 'exact_hash'])
            ->where(['run_id' => $run['run_id']])->orderBy('column_key')->all($this->db) : [],
            'wallet_ready' => WalletSchema::ready($this->db), 'activation_available' => $run && $run['phase'] === 'verified'];
    }

    public function begin(): array
    {
        return $this->exclusive(function () {
            if (WalletSchema::ready($this->db)) throw new \RuntimeException('The exact wallet is already active.');
            if (\common\modules\world\modules\craft\models\domain\StorageMaintenance::frozen($this->db)) throw new \RuntimeException('Finish the storage rollout before freezing the wallet.');
            $run = $this->run();
            if ($run && $run['phase'] !== 'cancelled') return $this->status();
            $this->requireEngines();
            $values = ['run_id' => bin2hex(random_bytes(16)), 'phase' => 'frozen', 'started_at' => time(), 'updated_at' => time()];
            if ($run) $this->db->createCommand()->update('economy_wallet_rollout', $values, ['id' => 1])->execute();
            else $this->db->createCommand()->insert('economy_wallet_rollout', ['id' => 1] + $values)->execute();
            return $this->status();
        });
    }

    public function snapshot(int $limit = 500): array
    {
        if ($limit < 1 || $limit > 2000) throw new \InvalidArgumentException('Snapshot limit must be 1..2000.');
        return $this->exclusive(function () use ($limit) {
            $run = $this->requireRun(['frozen', 'snapshotted']);
            foreach (self::COLUMNS as $key) {
                $state = $this->column($run, $key);
                if ($state && $state['phase'] === 'prepared') continue;
                if (!$state) {
                    list($table, $field) = explode('.', $key);
                    $schema = $this->db->schema->getTableSchema($table, true)->getColumn($field);
                    $this->db->createCommand()->insert('economy_wallet_column', [
                        'run_id' => $run['run_id'], 'column_key' => $key, 'original_type' => $schema->dbType,
                        'target_definition' => $this->definition($schema), 'phase' => 'copying', 'row_count' => 0,
                        'last_source_id' => null, 'raw_hash' => '', 'exact_hash' => '', 'created_at' => time(), 'updated_at' => time(),
                    ])->execute();
                    $state = $this->column($run, $key);
                }
                if ($state['phase'] !== 'copying' || $this->type($key) !== $state['original_type']) throw new \RuntimeException('Snapshot source schema changed: ' . $key);
                // Originals and cursor commit together, at most $limit rows per invocation.
                $this->db->transaction(function () use ($run, $key, $state, $limit) {
                    $count = 0; $cursor = $state['last_source_id'];
                    foreach ($this->rows($key, null, $cursor) as $row) {
                        $exact = $this->amount($key, $row);
                        $this->db->createCommand()->insert('economy_wallet_snapshot', [
                            'run_id' => $run['run_id'], 'column_key' => $key, 'source_id' => $row['id'],
                            'raw_amount' => $row['raw_amount'], 'exact_amount' => $exact,
                        ])->execute();
                        $cursor = $row['id']; $count++;
                        if ($count === $limit) break;
                    }
                    $this->db->createCommand()->update('economy_wallet_column', [
                        'last_source_id' => $cursor, 'row_count' => (int)$state['row_count'] + $count, 'updated_at' => time(),
                    ], ['run_id' => $run['run_id'], 'column_key' => $key])->execute();
                });
                // Sealing is read-only until the final digest write; no long snapshot write transaction.
                $state = $this->column($run, $key);
                $hasMore = false;
                foreach ($this->rows($key, null, $state['last_source_id']) as $row) { $hasMore = true; break; }
                if (!$hasMore) $this->compare($run, $key, true, true);
                break;
            }
            $complete = true;
            foreach (self::COLUMNS as $key) {
                $state = $this->column($run, $key);
                if (!$state || $state['phase'] !== 'prepared') { $complete = false; break; }
            }
            if ($complete) {
                foreach (self::COLUMNS as $key) $this->compare($run, $key, true);
                $this->phase('snapshotted');
            }
            return $this->status();
        });
    }

    public function convert(): array
    {
        return $this->exclusive(function () {
            $run = $this->requireRun(['snapshotted', 'converting', 'converted', 'verified']);
            $this->requireEngines();
            (new WalletCompatibility($this->db))->requireCompatible();
            // Check every original before the first irreversible ALTER, including on resume.
            foreach (self::COLUMNS as $key) {
                $state = $this->column($run, $key);
                if (!$state || !in_array($state['phase'], ['prepared', 'converting', 'converted'], true)) throw new \RuntimeException('Complete all credit snapshots first.');
                $exact = $this->isExact($key);
                if (!$exact && $this->type($key) !== $state['original_type']) throw new \RuntimeException('Unexpected column type: ' . $key);
                $this->compare($run, $key, $state['phase'] === 'prepared' || !$exact);
            }
            $this->phase('converting');
            foreach (self::COLUMNS as $key) {
                $state = $this->column($run, $key);
                $this->columnPhase($run, $key, 'converting'); // Durable before MySQL implicit commit.
                if (!$this->isExact($key)) {
                    list($table, $field) = explode('.', $key);
                    // Deliberately outside a transaction: MySQL/MariaDB DDL commits implicitly.
                    // PostgreSQL supports the same one-statement ALTER and can resume likewise.
                    if ($this->db->driverName === 'pgsql') {
                        // Yii's generic alterColumn drops DEFAULT/NOT NULL when omitted.
                        // Raw TYPE-only SQL preserves both and existing constraints/comments.
                        $this->db->createCommand('ALTER TABLE ' . $this->db->quoteTableName($table) . ' ALTER COLUMN ' .
                            $this->db->quoteColumnName($field) . ' TYPE DECIMAL(19,4)')->execute();
                    } else $this->db->createCommand()->alterColumn($table, $field, $state['target_definition'])->execute();
                    $this->db->schema->refreshTableSchema($table);
                }
                if (!$this->isExact($key)) throw new \RuntimeException('Decimal conversion failed: ' . $key);
                $this->compare($run, $key, false);
                $this->columnPhase($run, $key, 'converted');
            }
            $this->phase('converted');
            return $this->status();
        });
    }

    public function verify(): array
    {
        return $this->exclusive(function () {
            $run = $this->requireRun(['converted', 'verified']);
            $this->requireEngines();
            foreach (self::COLUMNS as $key) {
                if (!$this->isExact($key)) throw new \RuntimeException('Not an exact column: ' . $key);
                $this->compare($run, $key, false);
            }
            $this->phase('verified');
            // Remains frozen until a separate explicit activation, which repeats the checks.
            return $this->status();
        });
    }

    public function activate(): array
    {
        return $this->exclusive(function () {
            $run = $this->run();
            if ($run && $run['phase'] === 'active' && WalletSchema::ready($this->db)) return $this->status();
            $run = $this->requireRun(['verified']);
            $this->requireEngines();
            foreach (self::COLUMNS as $key) {
                if (!$this->isExact($key) || $this->column($run, $key)['phase'] !== 'converted') throw new \RuntimeException('Complete the exact credit conversion first.');
                $this->compare($run, $key, false);
            }
            (new WalletCompatibility($this->db))->requireCompatible();
            $this->db->transaction(function () use ($run) {
                $locks = new \common\services\user\CreditLedger($this->db);
                $registry = $locks->lock('economy_registry', ['id' => 1]);
                $current = $locks->lock('economy_wallet_rollout', ['id' => 1]);
                if (!$registry || $registry['wallet_ready'] || !$current || $current['run_id'] !== $run['run_id'] || $current['phase'] !== 'verified') throw new \RuntimeException('Wallet rollout state changed.');
                if ($this->db->createCommand()->update('economy_registry', ['wallet_ready' => 1], ['id' => 1, 'wallet_ready' => 0])->execute() !== 1) throw new \RuntimeException('Could not activate the exact wallet.');
                $this->phase('active');
            });
            return $this->status();
        });
    }

    public function cancel(): array
    {
        return $this->exclusive(function () {
            $run = $this->requireRun(['frozen', 'snapshotted', 'cancelled']);
            if ($run['phase'] === 'cancelled') return $this->status();
            foreach (self::COLUMNS as $key) if ($state = $this->column($run, $key)) {
                if (!in_array($state['phase'], ['copying', 'prepared'], true) || $this->type($key) !== $state['original_type']) throw new \RuntimeException('Cannot cancel after a column conversion.');
            }
            $this->phase('cancelled'); // Retain originals and hashes, even for cancelled runs.
            return $this->status();
        });
    }

    private function compare(array $run, string $key, bool $raw, bool $seal = false): void
    {
        $state = $this->column($run, $key);
        if (!$state) throw new \RuntimeException('Missing snapshot: ' . $key);
        $where = ['run_id' => $run['run_id'], 'column_key' => $key];
        if ((string)(new Query())->from('economy_wallet_snapshot')->where($where)->count('*', $this->db) !== (string)$state['row_count']) throw new \RuntimeException('Snapshot row count changed: ' . $key);
        $rawHash = hash_init('sha256'); $exactHash = hash_init('sha256'); $count = 0;
        // Match IDs as well as totals. A replacement/deletion cannot hide in an equal sum.
        foreach ($this->rows($key, $run) as $row) {
            if ($row['snapshot_raw'] === null) throw new \RuntimeException('Source row has no snapshot: ' . $key . '#' . $row['id']);
            $exact = $this->amount($key, $row);
            if ($exact !== (string)$row['snapshot_exact'] || ($raw && $row['raw_amount'] !== $row['snapshot_raw'])) throw new \RuntimeException('Credit differs from snapshot: ' . $key . '#' . $row['id']);
            $this->digest($rawHash, $row['id'], $row['snapshot_raw']);
            $this->digest($exactHash, $row['id'], $exact); $count++;
        }
        $rawDigest = hash_final($rawHash); $exactDigest = hash_final($exactHash);
        if ((string)$count !== (string)$state['row_count'] || (!$seal && ($rawDigest !== $state['raw_hash'] || $exactDigest !== $state['exact_hash']))) throw new \RuntimeException('Credit snapshot digest differs: ' . $key);
        if ($seal) $this->db->createCommand()->update('economy_wallet_column', [
            'phase' => 'prepared', 'raw_hash' => $rawDigest, 'exact_hash' => $exactDigest, 'updated_at' => time(),
        ], ['run_id' => $run['run_id'], 'column_key' => $key, 'phase' => 'copying'])->execute();
    }

    /** Keyset pages avoid loading a complete financial history into PHP memory. */
    private function rows(string $key, ?array $run = null, $cursor = null): \Generator
    {
        list($table, $field) = explode('.', $key);
        do {
            $column = $this->db->quoteColumnName('s.' . $field);
            $cast = 'CAST(' . $column . ' AS ' . ($this->db->driverName === 'mysql' ? 'CHAR' : 'TEXT') . ')';
            $query = (new Query())->select(['id' => 's.id', 'raw_amount' => new Expression($cast)])->from(['s' => $table])->orderBy(['s.id' => SORT_ASC])->limit(500);
            if ($cursor !== null) $query->andWhere(['>', 's.id', $cursor]);
            if ($run) $query->addSelect(['snapshot_raw' => 'b.raw_amount', 'snapshot_exact' => 'b.exact_amount'])->leftJoin(['b' => 'economy_wallet_snapshot'],
                '[[b.source_id]]=[[s.id]] AND [[b.run_id]]=:run AND [[b.column_key]]=:key', [':run' => $run['run_id'], ':key' => $key]);
            $rows = $query->all($this->db);
            foreach ($rows as $row) { $cursor = $row['id']; yield $row; }
        } while (count($rows) === 500);
    }

    private function amount(string $key, array $row): string
    {
        try {
            if ($row['raw_amount'] === null) throw new \InvalidArgumentException('Null amount.');
            // Test deployment adopts the wallet quantum for legacy fractional values.
            // The immutable snapshot retains raw_amount; production stays strict.
            $test = getenv('WORLD_TEST_SETUP') === 'confirmed-test-checkout' && getenv('WORLD_TEST_MODE') === '1';
            $amount = $test ? \common\modules\world\modules\economy\value\TestCreditConversion::amount((string)$row['raw_amount'], $key === 'persone.credit') : Money::parse((string)$row['raw_amount']);
            if ($key === 'persone.credit' && $amount->isNegative()) throw new \InvalidArgumentException('Negative personal credit.');
            return $amount->decimal();
        } catch (\InvalidArgumentException | \OverflowException $e) {
            throw new \RuntimeException('Explicit amount decision required at ' . $key . '#' . $row['id'] . ' (' . $e->getMessage() . ')');
        }
    }
    private function definition(\yii\db\ColumnSchema $column): string
    {
        if ($this->db->driverName === 'pgsql') return 'DECIMAL(19,4)'; // PostgreSQL retains default and nullability.
        $definition = 'DECIMAL(19,4)' . ($column->unsigned ? ' UNSIGNED' : '') . ($column->allowNull ? ' NULL' : ' NOT NULL');
        if ($column->defaultValue !== null) $definition .= ' DEFAULT ' . $this->db->quoteValue(Money::parse((string)$column->defaultValue)->decimal());
        elseif ($column->allowNull) $definition .= ' DEFAULT NULL';
        if ($column->comment !== null && $column->comment !== '') $definition .= ' COMMENT ' . $this->db->quoteValue($column->comment);
        return $definition;
    }
    private function digest($hash, $id, string $amount): void { hash_update($hash, json_encode([(string)$id, $amount], JSON_THROW_ON_ERROR) . "\n"); }
    private function run(): ?array { return (new Query())->from('economy_wallet_rollout')->where(['id' => 1])->one($this->db) ?: null; }
    private function column(array $run, string $key): ?array { return (new Query())->from('economy_wallet_column')->where(['run_id' => $run['run_id'], 'column_key' => $key])->one($this->db) ?: null; }
    private function type(string $key): string { list($table, $field) = explode('.', $key); return $this->db->schema->getTableSchema($table, true)->getColumn($field)->dbType; }
    private function isExact(string $key): bool
    {
        list($table, $field) = explode('.', $key); $column = $this->db->schema->getTableSchema($table, true)->getColumn($field);
        return $column->type === 'decimal' && (int)$column->precision === 19 && (int)$column->scale === 4;
    }
    private function phase(string $phase): void { $this->db->createCommand()->update('economy_wallet_rollout', ['phase' => $phase, 'updated_at' => time()], ['id' => 1])->execute(); }
    private function columnPhase(array $run, string $key, string $phase): void { $this->db->createCommand()->update('economy_wallet_column', ['phase' => $phase, 'updated_at' => time()], ['run_id' => $run['run_id'], 'column_key' => $key])->execute(); }
    private function requireRun(array $phases): array
    {
        $run = $this->run();
        if (!$run || !in_array($run['phase'], $phases, true) || WalletSchema::ready($this->db)) throw new \RuntimeException('Wallet rollout is not in the required phase.');
        return $run;
    }
    private function requireSchema(): void
    {
        if (!in_array($this->db->driverName, ['mysql', 'pgsql'], true)) throw new \RuntimeException('Wallet rollout requires MySQL/MariaDB or PostgreSQL.');
        foreach (['economy_registry', 'economy_wallet_rollout', 'economy_wallet_column', 'economy_wallet_snapshot'] as $table) if (!$this->db->schema->getTableSchema($table)) throw new \RuntimeException('Install wallet rollout schema first.');
    }
    private function requireEngines(): void
    {
        if ($this->db->driverName !== 'mysql') return;
        foreach (['persone', 'history_balance', 'economy_registry', 'economy_wallet_rollout', 'economy_wallet_column', 'economy_wallet_snapshot'] as $table) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
            if (strtolower((string)$engine) !== 'innodb') throw new \RuntimeException('Wallet rollout requires InnoDB: ' . $table);
        }
    }
    private function exclusive(callable $work): array
    {
        if (getenv('WORLD_INSTALL') !== 'confirmed-world-install' || getenv('WORLD_WALLET_MAINTENANCE') !== '1') throw new \RuntimeException('Drain all web, worker, cron and registration writers; set explicit WORLD_INSTALL and WORLD_WALLET_MAINTENANCE=1.');
        $this->requireSchema();
        if ($this->db->getTransaction()) throw new \RuntimeException('Wallet DDL must not run inside an outer transaction.');
        $mysql = $this->db->driverName === 'mysql';
        $acquired = $this->db->createCommand($mysql ? "SELECT GET_LOCK('ablaki.world.wallet-rollout', 0)" : 'SELECT pg_try_advisory_lock(7260927, 106000)')->queryScalar();
        if (!in_array($acquired, [true, 1, '1', 't'], true)) throw new \RuntimeException('Another wallet rollout command is running.');
        try { return $work(); }
        finally { $this->db->createCommand($mysql ? "SELECT RELEASE_LOCK('ablaki.world.wallet-rollout')" : 'SELECT pg_advisory_unlock(7260927, 106000)')->queryScalar(); }
    }
}
