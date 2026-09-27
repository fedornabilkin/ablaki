<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\services\game\GameError;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Internal domain API. No arbitrary public spending/withdrawal endpoint. */
class BudgetSpending
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function writable(): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Budget spending requires a domain transaction.');
        WalletSchema::requireReady($this->db); (new Locks($this->db))->row('world_registry', ['id' => 1]);
    }
    public function account(int $id): array
    {
        $row = (new Query())->from('economy_account')->where(['id' => $id, 'role' => 'budget', 'currency' => 'Cr'])->one($this->db);
        if (!$row) throw new GameError('BUDGET_UNAVAILABLE', 'Бюджет недоступен.');
        $amount = Money::parse((string)$row['amount']); $reserved = Money::parse((string)$row['reserved']);
        if ($reserved->isNegative() || $amount->compare($reserved) < 0) throw new \RuntimeException('Invalid budget reserve.');
        return $row;
    }
    public function available(int $account): Money
    {
        $row = $this->account($account);
        return Money::parse((string)$row['amount'])->subtract(Money::parse((string)$row['reserved']));
    }
    private function update(string $table, array $values, array $where): void
    {
        if ($this->db->createCommand()->update($table, $values, $where)->execute() !== 1) throw new \RuntimeException('Budget spending update failed.');
    }
    public function reserve(int $account, Money $amount, string $purpose, string $operation): int
    {
        $this->writable(); $budget = $this->account($account);
        if ($amount->isZero() || $amount->isNegative() || $this->available($account)->compare($amount) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Недостаточно свободных средств бюджета.');
        $this->update('economy_account', ['reserved' => Money::parse((string)$budget['reserved'])->add($amount)->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $account]);
        $this->db->createCommand()->insert('economy_spending_commitment', ['account_id' => $account, 'operation_id' => $operation, 'purpose' => $purpose,
            'original_amount' => $amount->decimal(), 'remaining_amount' => $amount->decimal(), 'created_at' => time()])->execute();
        return (int)$this->db->getLastInsertID();
    }
    public function commitment(int $id): array
    {
        $row = (new Query())->from('economy_spending_commitment')->where(['id' => $id, 'closed_at' => null])->one($this->db);
        if (!$row) throw new GameError('ORDER_RESERVE_UNAVAILABLE', 'Резерв заказа уже закрыт.');
        return $row;
    }
    public function release(int $id): void
    {
        $this->writable(); $hold = $this->commitment($id); $account = $this->account((int)$hold['account_id']);
        $reserve = Money::parse((string)$account['reserved'])->subtract(Money::parse((string)$hold['remaining_amount']));
        if ($reserve->isNegative()) throw new \RuntimeException('Commitment exceeds reserve.');
        $this->update('economy_account', ['reserved' => $reserve->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $account['id']]);
        $this->update('economy_spending_commitment', ['remaining_amount' => '0.0000', 'closed_at' => time()], ['id' => $id, 'closed_at' => null]);
    }
    public function allocation(int $account, Money $amount): array
    {
        $needed = $amount; $result = [];
        $lots = (new Query())->from('economy_funding_lot')->where(['budget_account_id' => $account])->andWhere(['>', 'remaining_amount', 0])->orderBy(['id' => SORT_ASC])->limit(201)->all($this->db);
        foreach ($lots as $lot) {
            if ($needed->isZero()) break;
            if (count($result) >= 200) throw new GameError('SPENDING_BATCH_LIMIT', 'Для оплаты нужно обработать слишком много вкладов. Уменьшите количество сдаваемых предметов.');
            $remaining = Money::parse((string)$lot['remaining_amount']); $take = $remaining->compare($needed) < 0 ? $remaining : $needed;
            $result[] = ['id' => (int)$lot['id'], 'amount' => $take->decimal(), 'remaining_after' => $remaining->subtract($take)->decimal()];
            $needed = $needed->subtract($take);
        }
        return $result;
    }
    /** Allocates only an authorised commitment. Payment, provenance and recipient receipt share its transaction. */
    public function pay(int $commitment, int $recipientNode, Money $amount, string $operation): int
    {
        $this->writable(); $hold = $this->commitment($commitment); $account = $this->account((int)$hold['account_id']);
        $left = Money::parse((string)$hold['remaining_amount'])->subtract($amount);
        $reserve = Money::parse((string)$account['reserved'])->subtract($amount);
        if ($amount->isZero() || $amount->isNegative() || $left->isNegative() || $reserve->isNegative()) throw new GameError('ORDER_RESERVE_UNAVAILABLE', 'Средств заказа недостаточно.');
        $allocations = $this->allocation((int)$account['id'], $amount);
        $this->update('economy_account', ['reserved' => $reserve->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $account['id']]);
        $transfer = (new TreasuryLedger($this->db))->receiveBudgetPayment((int)$account['id'], $recipientNode, $amount, $operation, $hold['purpose']);
        $this->update('economy_spending_commitment', ['remaining_amount' => $left->decimal(), 'closed_at' => $left->isZero() ? time() : null], ['id' => $commitment, 'closed_at' => null]);
        // FIFO personal contributions first; the remainder is previously collected income.
        foreach ($allocations as $lot) {
            $this->update('economy_funding_lot', ['remaining_amount' => $lot['remaining_after']], ['id' => $lot['id']]);
            $this->db->createCommand()->insert('economy_spending_allocation', ['transfer_id' => $transfer, 'funding_lot_id' => $lot['id'], 'amount' => $lot['amount']])->execute();
        }
        return $transfer;
    }
}
