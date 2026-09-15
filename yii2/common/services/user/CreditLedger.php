<?php

namespace common\services\user;

use RuntimeException;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\web\UnprocessableEntityHttpException;

/** Used inside the caller's transaction; records each credit movement atomically. */
class CreditLedger
{
    private $db;

    public function __construct(Connection $db) { $this->db = $db; }

    public function lock(string $table, array $condition): ?array
    {
        if (!$this->db->getTransaction()) throw new RuntimeException('Transaction required.');
        $query = (new Query())->from($table)->where($condition);
        if ($this->db->driverName === 'sqlite') {
            $column = key($condition);
            $this->db->createCommand('UPDATE ' . $this->db->quoteTableName($table) .
                ' SET [[id]]=[[id]] WHERE ' . $this->db->quoteColumnName($column) . '=:id', [':id' => $condition[$column]])->execute();
            return $query->one($this->db) ?: null;
        }
        if (!in_array($this->db->driverName, ['pgsql', 'mysql'], true)) throw new RuntimeException('Unsupported database.');
        $command = $query->createCommand($this->db);
        return $this->db->createCommand($command->sql . ' FOR UPDATE', $command->params)->queryOne() ?: null;
    }

    public function change(int $userId, float $amount, string $type, string $comment): void
    {
        $person = $this->lock('persone', ['user_id' => $userId]);
        if (!$person || !is_finite($amount) || !is_numeric($person['credit'])) throw new RuntimeException('Account unavailable.');
        $condition = ['user_id' => $userId];
        if ($amount < 0) $condition = ['and', $condition, ['>=', 'credit', -$amount]];
        if ($this->db->createCommand()->update('persone', ['credit' => new Expression('[[credit]] + :amount', [':amount' => $amount])], $condition)->execute() !== 1) {
            throw new UnprocessableEntityHttpException('Недостаточно кредитов.');
        }
        $updated = (new Query())->from('persone')->where(['user_id' => $userId])->one($this->db);
        if ($this->db->createCommand()->insert('history_balance', [
            'user_id' => $userId, 'balance' => $updated['balance'], 'credit' => $updated['credit'],
            'balance_up' => 0, 'credit_up' => $amount, 'type' => $type, 'comment' => $comment, 'created_at' => time(),
        ])->execute() !== 1) throw new RuntimeException('Could not record credit history.');
    }
}
