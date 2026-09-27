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
        if (\common\modules\economy\service\WalletSchema::ready($this->db)) {
            $this->changeExact($userId, \common\modules\economy\value\Money::fromLegacy($amount)->decimal(), $type, $comment);
            return;
        }
        $this->changeCurrency($userId, $amount, $type, $comment, 'credit');
    }

    /** Exact world/legacy credit path. The personal balance is never copied into a second wallet. */
    public function changeExact(int $userId, string $amount, string $type, string $comment): string
    {
        return $this->changeExactWithBalance($userId, $amount, 0, $type, $comment)['credit'];
    }

    /** Legacy exchanges can change both currencies; keep their single history entry. */
    public function changeExactWithBalance(int $userId, string $amount, float $balanceDelta, string $type, string $comment): array
    {
        \common\modules\economy\service\WalletMaintenance::writable($this->db);
        \common\modules\economy\service\WalletSchema::requireReady($this->db);
        if (!is_finite($balanceDelta)) throw new \InvalidArgumentException('Invalid balance delta.');
        $delta = \common\modules\economy\value\Money::parse($amount);
        $person = $this->lock('persone', ['user_id' => $userId]);
        if (!$person) throw new RuntimeException('Account unavailable.');
        $current = \common\modules\economy\value\Money::parse((string)$person['credit']);
        $next = $current->add($delta);
        if ($next->isNegative()) throw new UnprocessableEntityHttpException('Недостаточно кредитов.');
        if ($delta->isZero() && $balanceDelta == 0) return ['credit' => $current->decimal(), 'balance' => $person['balance']];
        $values = ['credit' => $next->decimal()];
        $condition = ['user_id' => $userId, 'credit' => $current->decimal()];
        if ($balanceDelta != 0) {
            if (!is_numeric($person['balance']) || !is_finite((float)$person['balance'] + $balanceDelta)) throw new RuntimeException('Account unavailable.');
            $values['balance'] = new Expression('[[balance]] + :delta', [':delta' => $balanceDelta]);
            if ($balanceDelta < 0) $condition = ['and', $condition, ['>=', 'balance', -$balanceDelta]];
        }
        if ($this->db->createCommand()->update('persone', $values, $condition)->execute() !== 1) throw new UnprocessableEntityHttpException('Недостаточно средств или баланс изменился.');
        $balance = (new Query())->select('balance')->from('persone')->where(['user_id' => $userId])->scalar($this->db);
        if ($this->db->createCommand()->insert('history_balance', [
            'user_id' => $userId, 'balance' => $balance, 'credit' => $next->decimal(), 'balance_up' => $balanceDelta,
            'credit_up' => $delta->decimal(), 'type' => $type, 'comment' => $comment, 'created_at' => time(),
        ])->execute() !== 1) throw new RuntimeException('Could not record exact credit history.');
        return ['credit' => $next->decimal(), 'balance' => $balance];
    }

    public function changeBalance(int $userId, float $amount, string $type, string $comment): void
    {
        if (\common\modules\economy\service\WalletSchema::ready($this->db)) {
            $this->changeExactWithBalance($userId, '0', $amount, $type, $comment);
            return;
        }
        $this->changeCurrency($userId, $amount, $type, $comment, 'balance');
    }

    private function changeCurrency(int $userId, float $amount, string $type, string $comment, string $currency): void
    {
        \common\modules\economy\service\WalletMaintenance::writable($this->db);
        $person = $this->lock('persone', ['user_id' => $userId]);
        if (!$person || !is_finite($amount) || !is_numeric($person[$currency])) throw new RuntimeException('Account unavailable.');
        $condition = ['user_id' => $userId];
        if ($amount < 0) $condition = ['and', $condition, ['>=', $currency, -$amount]];
        if ($this->db->createCommand()->update('persone', [$currency => new Expression('[[' . $currency . ']] + :amount', [':amount' => $amount])], $condition)->execute() !== 1) {
            throw new UnprocessableEntityHttpException('Недостаточно средств.');
        }
        $updated = (new Query())->from('persone')->where(['user_id' => $userId])->one($this->db);
        if ($this->db->createCommand()->insert('history_balance', [
            'user_id' => $userId, 'balance' => $updated['balance'], 'credit' => $updated['credit'],
            'balance_up' => $currency === 'balance' ? $amount : 0, 'credit_up' => $currency === 'credit' ? $amount : 0,
            'type' => $type, 'comment' => $comment, 'created_at' => time(),
        ])->execute() !== 1) throw new RuntimeException('Could not record credit history.');
    }
}
