<?php
namespace common\modules\world\support;

use common\modules\world\modules\economy\models\domain\WalletCompatibility;
use common\modules\world\modules\economy\models\domain\WalletRollout;
use yii\db\Connection;
use yii\db\Query;

/** Explicit operator workflow, called only after all monetary writers have drained. */
final class ProductionDatabasePreparation
{
    private const TABLES = ['history_balance', 'history_rating', 'advertising', 'comission', 'credit_exchange',
        'credit_transfer', 'forum_comment', 'game_duel', 'game_five', 'game_five_hod', 'game_orel', 'game_saper'];

    public function __construct(private Connection $db, private string $directory) {}

    public function prepareEngines(callable $progress): void
    {
        if ($this->db->driverName !== 'mysql' || $this->db->getTransaction()) {
            throw new \RuntimeException('Engine preparation requires MySQL without an outer transaction.');
        }
        foreach (self::TABLES as $table) {
            $engine = strtolower((string)$this->db->createCommand(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table',
                [':table' => $table]
            )->queryScalar());
            $file = $this->directory . '/engine-' . $table . '.json';
            $checkpoint = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : null;
            if ($checkpoint && $checkpoint['verified']) {
                if ($engine !== 'innodb') throw new \RuntimeException('A verified engine changed: ' . $table);
                continue;
            }
            if (!$checkpoint && $engine === 'innodb') continue;
            if (!in_array($engine, ['myisam', 'innodb'], true)) throw new \RuntimeException('Unexpected engine: ' . $table);
            $current = $this->signature($table);
            if ($checkpoint && $checkpoint['before'] !== $current) throw new \RuntimeException('Engine checkpoint mismatch: ' . $table);
            $checkpoint ??= ['before' => $current, 'verified' => false];
            $this->save($file, $checkpoint); // Durable before implicit DDL commit; resume never invents a baseline.
            if ($engine !== 'innodb') {
                $this->db->createCommand('ALTER TABLE ' . $this->db->quoteTableName($table) . ' ENGINE=InnoDB')->execute();
                $this->db->schema->refreshTableSchema($table);
            }
            if ($checkpoint['before'] !== $this->signature($table)) throw new \RuntimeException('Engine conversion changed rows or definitions: ' . $table);
            $checkpoint['verified'] = true;
            $this->save($file, $checkpoint);
            $progress('engine-verified', ['table' => $table, 'rows' => $current['rows']]);
        }
    }

    public function prepareWallet(callable $progress): array
    {
        $rollout = new WalletRollout($this->db);
        $status = $rollout->status();
        if (!$status['rollout'] || $status['rollout']['phase'] === 'cancelled') $status = $rollout->begin('half-up-4');
        if (($status['rollout']['precision_policy'] ?? '') !== 'half-up-4') throw new \RuntimeException('Unexpected saved precision policy.');
        $lastReport = 0;
        while ($status['rollout']['phase'] === 'frozen') {
            $status = $rollout->snapshot(2000);
            if (time() - $lastReport >= 15) {
                $progress('snapshot', ['rows' => array_sum(array_column($status['columns'], 'row_count'))]);
                $lastReport = time();
            }
        }
        if (in_array($status['rollout']['phase'], ['snapshotted', 'converting'], true)) {
            $progress('decimal-conversion', []);
            $status = $rollout->convert();
        }
        if ($status['rollout']['phase'] === 'converted') {
            $progress('verifying-amounts', []);
            $status = $rollout->verify();
        }
        if ($status['rollout']['phase'] === 'verified') {
            $progress('activating-wallet', []);
            $status = $rollout->activate();
        }
        if ($status['rollout']['phase'] !== 'active' || !$status['wallet_ready']) throw new \RuntimeException('Wallet activation is incomplete.');
        (new WalletCompatibility($this->db))->requireCompatible();
        $rounded = 0;
        $query = (new Query())->select('raw_amount')->from('economy_wallet_snapshot')
            ->where(['run_id' => $status['rollout']['run_id'], 'column_key' => 'persone.credit']);
        foreach ($query->each(500, $this->db) as $row) {
            if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]{1,3}))?$/D', $row['raw_amount'], $parts)) throw new \RuntimeException('Invalid verified snapshot.');
            $digits = $parts[2] . ($parts[3] ?? '');
            $trailingZeros = strlen($digits) - strlen(rtrim($digits, '0'));
            if (trim($digits, '0') !== '' && strlen($parts[3] ?? '') - (int)($parts[4] ?? 0) - $trailingZeros > 4) $rounded++;
        }
        return ['phase' => 'active', 'personal_rounded' => $rounded, 'columns' => $status['columns']];
    }

    /** Ordered streaming avoids loading the million-row ledger into PHP memory. */
    private function signature(string $table): array
    {
        $schema = $this->db->schema->getTableSchema($table, true);
        if (!$schema || !$schema->primaryKey) throw new \RuntimeException('Stable row order is required: ' . $table);
        $columns = $this->db->createCommand('SHOW FULL COLUMNS FROM ' . $this->db->quoteTableName($table))->queryAll();
        $indexes = $this->db->createCommand('SHOW INDEX FROM ' . $this->db->quoteTableName($table))->queryAll();
        foreach ($indexes as &$index) unset($index['Cardinality']);
        unset($index);
        usort($indexes, static fn(array $a, array $b): int => [$a['Key_name'], (int)$a['Seq_in_index']] <=> [$b['Key_name'], (int)$b['Seq_in_index']]);
        $hash = hash_init('sha256'); $count = 0;
        $pdo = $this->db->pdo;
        $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $reader = null;
        try {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $reader = (new Query())->from($table)->orderBy(array_fill_keys($schema->primaryKey, SORT_ASC))->createCommand($this->db)->query();
            foreach ($reader as $row) {
                foreach ($row as $value) hash_update($hash, $value === null ? 'N;' : strlen((string)$value) . ':' . (string)$value . ';');
                hash_update($hash, "\n"); $count++;
            }
        } finally {
            if ($reader) $reader->close();
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
        return ['columns' => $columns, 'indexes' => $indexes, 'rows' => $count, 'sha256' => hash_final($hash)];
    }

    private function save(string $file, array $data): void
    {
        $temporary = $file . '.new';
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !rename($temporary, $file)) throw new \RuntimeException('Cannot persist preparation checkpoint.');
    }
}
