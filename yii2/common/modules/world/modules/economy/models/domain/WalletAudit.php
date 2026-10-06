<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Read-only preflight. Values needing rounding are reported, never silently rewritten. */
class WalletAudit
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function report(): array
    {
        if (!in_array($this->db->driverName, ['mysql', 'pgsql'], true)) throw new \RuntimeException('Credit migration preflight requires MySQL/MariaDB or PostgreSQL.');
        $result = ['read_only' => true, 'generated_at' => time(), 'columns' => [], 'activation_allowed' => false];
        foreach (['persone' => ['credit'], 'history_balance' => ['credit', 'credit_up']] as $table => $fields) foreach ($fields as $field) {
            $quoted = $this->db->quoteColumnName($field); $cast = $this->db->driverName === 'mysql' ? 'CAST(' . $quoted . ' AS CHAR)' : 'CAST(' . $quoted . ' AS TEXT)';
            $digest = hash_init('sha256'); $rows = 0; $invalid = 0; $samples = [];
            foreach ((new Query())->select(['id', 'raw_amount' => new Expression($cast)])->from($table)->orderBy(['id' => SORT_ASC])->each(500, $this->db) as $row) {
                $rows++; hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
                try {
                    if ($row['raw_amount'] === null) throw new \InvalidArgumentException('null-amount');
                    $amount = Money::parse((string)$row['raw_amount']);
                    if ($table === 'persone' && $amount->isNegative()) throw new \InvalidArgumentException('negative-personal-credit');
                } catch (\InvalidArgumentException $e) { $invalid++; if (count($samples) < 30) $samples[] = ['id' => (int)$row['id'], 'reason' => 'format-or-precision']; }
                catch (\OverflowException $e) { $invalid++; if (count($samples) < 30) $samples[] = ['id' => (int)$row['id'], 'reason' => 'overflow']; }
            }
            $column = $this->db->schema->getTableSchema($table)->getColumn($field);
            $result['columns'][$table . '.' . $field] = ['type' => $column->dbType, 'rows' => $rows, 'requires_decision' => $invalid, 'samples' => $samples, 'sha256' => hash_final($digest)];
        }
        $result['legacy'] = (new WalletCompatibility($this->db))->report();
        $result['note'] = 'Pause all financial writers for a consistent snapshot. This report changes no columns. Explicit wallet-activate rechecks converted values and open legacy positions; this report alone never permits activation.';
        return $result;
    }
}
