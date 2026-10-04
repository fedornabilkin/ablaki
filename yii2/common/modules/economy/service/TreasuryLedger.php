<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\services\game\CanonicalJson;
use common\services\game\GameError;
use common\services\game\JobQueue;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Internal funded transfers only. Caller holds the world command transaction/registry lock. */
class TreasuryLedger
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function money($value): Money { return Money::parse((string)$value); }
    private function account(int $id): array
    {
        $row = (new Query())->from('economy_account')->where(['id' => $id, 'currency' => 'Cr'])->one($this->db);
        if (!$row) throw new GameError('ACCOUNT_UNAVAILABLE', 'Счёт недоступен.');
        $amount = $this->money($row['amount']); $reserved = $this->money($row['reserved']);
        if ($amount->isNegative() || $reserved->isNegative() || $reserved->compare($amount) > 0) throw new \RuntimeException('Invalid account balance/reserve.');
        return $row;
    }
    public function published(int $node): array
    {
        $rule = (new EconomyHierarchy($this->db))->current($node);
        if (!$rule || $rule['status'] !== 'published' || !$rule['loss_policy']) throw new GameError('FINANCE_POLICY_REQUIRED', 'Для объекта ещё не опубликованы правила отчислений и защиты казны.');
        return $rule;
    }
    private function writable(): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Treasury writes require a command transaction.');
        WalletSchema::requireReady($this->db);
        if (!(new Locks($this->db))->row('world_registry', ['id' => 1])) throw new \RuntimeException('World registry unavailable.');
    }
    private function balance(int $id, Money $amount, Money $reserved): void
    {
        if ($amount->isNegative() || $reserved->isNegative() || $reserved->compare($amount) > 0) throw new \RuntimeException('Account invariant violated.');
        if ($this->db->createCommand()->update('economy_account', ['amount' => $amount->decimal(), 'reserved' => $reserved->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $id])->execute() !== 1) throw new \RuntimeException('Account update failed.');
    }
    private function updateOne(string $table, array $values, array $where): void
    {
        if ($this->db->createCommand()->update($table, $values, $where)->execute() !== 1) throw new \RuntimeException('Treasury state update failed.');
    }
    private function transfer(int $sourceId, int $destinationId, Money $amount, string $operation, string $line, string $kind, string $purpose): int
    {
        if ($sourceId === $destinationId || $amount->isZero() || $amount->isNegative()) throw new \LogicException('Invalid funded transfer.');
        $source = $this->account($sourceId); $destination = $this->account($destinationId);
        $after = $this->money($source['amount'])->subtract($amount);
        if ($after->compare($this->money($source['reserved'])) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Свободных средств недостаточно.');
        $received = $this->money($destination['amount'])->add($amount);
        $this->balance($sourceId, $after, $this->money($source['reserved']));
        $this->balance($destinationId, $received, $this->money($destination['reserved']));
        $this->db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => $line, 'kind' => $kind, 'source_account_id' => $sourceId, 'source_user_id' => null,
            'destination_account_id' => $destinationId, 'amount' => $amount->decimal(), 'source_after' => $after->decimal(), 'destination_after' => $received->decimal(), 'purpose' => $purpose, 'created_at' => time()])->execute();
        return (int)$this->db->getLastInsertID();
    }
    private function recipient(array $rule): ?array
    {
        if ($rule['parent_node_id'] === null) return null;
        $accounts = (new EconomyHierarchy($this->db))->accounts($rule['parent_node_id']);
        if (!isset($accounts['treasury'])) throw new GameError('PARENT_ACCOUNT_UNAVAILABLE', 'Казна получателя недоступна.');
        return $accounts['treasury'];
    }
    public function catchingUp(int $account, int $now): bool
    {
        return (new Query())->from(['r' => 'economy_treasury_receipt'])
            ->innerJoin(['p' => 'economy_collection_policy'], '[[p.history_id]]=[[r.history_id]]')
            ->where(['r.account_id' => $account, 'r.closed_at' => null])->andWhere(['>', 'p.loss_rate_bps', 0])->andWhere(['<=', 'r.next_loss_at', $now])->exists($this->db);
    }
    /** HTTP only collects already settled receipts. Loss catch-up belongs exclusively to the worker. */
    public function planCollection(int $node, array $accounts, int $now): array
    {
        $rule = $this->published($node); $treasury = $accounts['treasury']; $budget = $accounts['budget'];
        if ($this->catchingUp((int)$treasury['id'], $now)) throw new GameError('TREASURY_CATCHING_UP', 'Расчёт потерь казны ещё выполняется. Обновите данные немного позже.', 409, ['retry_after' => 30]);
        $base = (new Query())->from('economy_treasury_receipt')->where(['account_id' => $treasury['id'], 'closed_at' => null]);
        $sum = (clone $base)->sum('remaining_amount', $this->db);
        if ($this->money($sum === null ? '0' : $sum)->compare($this->money($treasury['amount'])) !== 0) throw new GameError('TREASURY_RECONCILIATION_REQUIRED', 'История поступлений казны требует сверки.', 503);
        $rows = $base->orderBy(['received_at' => SORT_ASC, 'id' => SORT_ASC])->limit(101)->all($this->db);
        if (!$rows) throw new GameError('TREASURY_EMPTY', 'В казне нет поступлений для сбора.');
        $more = count($rows) > 100; $rows = array_slice($rows, 0, 100);
        $plans = []; $collected = $this->money('0');
        foreach ($rows as $row) {
            $take = $this->money($row['remaining_amount']); $collected = $collected->add($take);
            $plans[] = ['id' => (int)$row['id'], 'collect' => $take->decimal()];
        }
        $checkpoint = (new Query())->from('economy_tax_checkpoint')->where(['history_id' => $rule['history_id']])->one($this->db);
        if (!$checkpoint) throw new \RuntimeException('Tax checkpoint missing.');
        $tax = $collected->portion($rule['rate_bps'], (int)$checkpoint['fraction']);
        $recipient = $this->recipient($rule);
        if ($recipient === null && !$tax['amount']->isZero()) throw new \RuntimeException('Tax has no recipient.');
        $budgetAfter = $this->money($budget['amount'])->add($collected); $reservedAfter = $this->money($budget['reserved'])->add($tax['amount']);
        return ['plans' => $plans, 'rule' => $rule, 'tax_fraction' => $tax['carry'], 'recipient' => $recipient, 'terms' => [
            'node_id' => $node, 'collected' => $collected->decimal(), 'reserved_for_parent' => $tax['amount']->decimal(),
            'budget_after' => $budgetAfter->decimal(), 'available_after' => $budgetAfter->subtract($reservedAfter)->decimal(),
            'treasury_after' => $this->money($treasury['amount'])->subtract($collected)->decimal(),
            'rule_revision' => $rule['revision'], 'parent_node_id' => $rule['parent_node_id'], 'due_seconds' => $rule['due_seconds'],
            'more_pending' => $more, 'plan_hash' => hash('sha256', CanonicalJson::encode($plans)),
        ]];
    }
    private function lossAccount(int $node): int
    {
        $root = (new Query())->select('root_id')->from('world_node')->where(['id' => $node])->scalar($this->db);
        $accounts = (new EconomyHierarchy($this->db))->accounts((int)$root);
        if (!isset($accounts['budget'])) throw new \RuntimeException('World accounts missing.');
        if (!isset($accounts['loss'])) {
            $this->db->createCommand()->insert('economy_account', ['subject_id' => $accounts['budget']['subject_id'], 'role' => 'loss', 'currency' => 'Cr', 'amount' => '0.0000', 'reserved' => '0.0000'])->execute();
            return (int)$this->db->getLastInsertID();
        }
        return (int)$accounts['loss']['id'];
    }
    public function collect(int $node, array $accounts, array $plan, string $operation): void
    {
        $this->writable(); $treasuryId = (int)$accounts['treasury']['id']; $budgetId = (int)$accounts['budget']['id']; $now = time();
        $collected = $this->money($plan['terms']['collected']); $tax = $this->money($plan['terms']['reserved_for_parent']); $transfer = null;
        if (!$collected->isZero()) $transfer = $this->transfer($treasuryId, $budgetId, $collected, $operation, 'collect', 'treasury_collection', 'Сбор казны в бюджет объекта');
        foreach ($plan['plans'] as $receipt) {
            $take = $this->money($receipt['collect']);
            if (!$take->isZero()) $this->db->createCommand()->insert('economy_receipt_collection', ['receipt_id' => $receipt['id'], 'transfer_id' => $transfer, 'amount' => $take->decimal()])->execute();
            $this->updateOne('economy_treasury_receipt', ['remaining_amount' => '0.0000', 'closed_at' => $now], ['id' => $receipt['id'], 'closed_at' => null]);
        }
        // Even a sub-unit tax is remembered; splitting collection cannot erase the fractional debt.
        $this->db->createCommand()->update('economy_tax_checkpoint', ['fraction' => $plan['tax_fraction']], ['history_id' => $plan['rule']['history_id']])->execute();
        if (!$tax->isZero()) {
            $budget = $this->account($budgetId);
            $this->balance($budgetId, $this->money($budget['amount']), $this->money($budget['reserved'])->add($tax));
            $this->db->createCommand()->insert('economy_obligation', ['collection_transfer_id' => $transfer, 'history_id' => $plan['rule']['history_id'],
                'budget_account_id' => $budgetId, 'recipient_account_id' => $plan['recipient']['id'], 'amount' => $tax->decimal(),
                'status' => 'pending', 'created_at' => $now, 'due_at' => $now + $plan['rule']['due_seconds']])->execute();
            $this->db->createCommand()->insert('economy_budget_reservation', ['obligation_id' => (int)$this->db->getLastInsertID(), 'account_id' => $budgetId, 'amount' => $tax->decimal()])->execute();
        }
    }
    /** Called only by JobQueue::perform while the live lease/fence and registry are locked. */
    public function runLoss(array $payload): void
    {
        $this->writable();
        if (!is_int($payload['receipt_id'] ?? null) || !is_int($payload['sequence'] ?? null)) throw new \LogicException('Invalid treasury loss job.');
        $row = (new Query())->from('economy_treasury_receipt')->where(['id' => $payload['receipt_id']])->one($this->db);
        if (!$row || $row['closed_at'] !== null || (int)$row['loss_sequence'] >= $payload['sequence']) return;
        if ((int)$row['loss_sequence'] + 1 !== $payload['sequence']) throw new \RuntimeException('Loss checkpoint gap.');
        $policy = (new Query())->from('economy_collection_policy')->where(['history_id' => $row['history_id']])->one($this->db);
        if (!$policy) throw new \RuntimeException('Receipt policy missing.');
        $next = (int)$row['next_loss_at']; $now = time();
        if ((int)$policy['loss_rate_bps'] === 0) return;
        if ($next > $now) throw new \RuntimeException('Loss job became available before its period.');
        $account = $this->account((int)$row['account_id']);
        $node = (new Query())->select('node_id')->from('economy_subject')->where(['id' => $account['subject_id']])->scalar($this->db);
        if (!$node || $account['role'] !== 'treasury') throw new \RuntimeException('Invalid treasury receipt account.');
        $operation = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'economy.treasury.loss', 'created_at' => $now])->execute();
        $remaining = $this->money($row['remaining_amount']); $fraction = (int)$row['loss_fraction']; $sequence = (int)$row['loss_sequence']; $sink = null;
        for ($period = 0; $period < 200 && $next <= $now && !$remaining->isZero(); $period++) {
            $part = $remaining->portion((int)$policy['loss_rate_bps'], $fraction);
            $remaining = $remaining->subtract($part['amount']); $fraction = $part['carry']; $sequence++; $transfer = null;
            if (!$part['amount']->isZero()) {
                if ($sink === null) $sink = $this->lossAccount((int)$node);
                $transfer = $this->transfer((int)$account['id'], $sink, $part['amount'], $operation, 'loss:' . $row['id'] . ':' . $sequence, 'treasury_loss', 'Потеря несобранной казны');
            }
            $this->db->createCommand()->insert('economy_treasury_loss', ['receipt_id' => $row['id'], 'sequence' => $sequence, 'period_at' => $next,
                'amount' => $part['amount']->decimal(), 'fraction_after' => $fraction, 'transfer_id' => $transfer, 'operation_id' => $operation])->execute();
            $next += (int)$policy['loss_period_seconds'];
        }
        $this->updateOne('economy_treasury_receipt', ['remaining_amount' => $remaining->decimal(), 'next_loss_at' => $next,
            'loss_sequence' => $sequence, 'loss_fraction' => $fraction, 'closed_at' => $remaining->isZero() ? $now : null], ['id' => $row['id'], 'loss_sequence' => $row['loss_sequence'], 'closed_at' => null]);
        // Zero-Cr periods still advance the checkpoint and invalidate older collection previews.
        $this->db->createCommand()->update('economy_account', ['revision' => new Expression('[[revision]]+1')], ['id' => $account['id']])->execute();
        if (!$remaining->isZero()) $this->scheduleLoss((int)$row['id'], $sequence + 1, $next);
        (new \common\services\game\CommandBus($this->db, new \common\modules\world\service\WorldFlags($this->db, \Yii::$app->getModule('world'))))
            ->emit($operation, null, 'economy.treasury.loss', ['node_id' => (int)$node, 'receipt_id' => (int)$row['id'], 'through_sequence' => $sequence]);
    }
    private function scheduleLoss(int $receipt, int $sequence, int $at): void
    {
        (new JobQueue($this->db))->enqueue('economy.treasury.loss', 'receipt:' . $receipt . ':' . $sequence, ['receipt_id' => $receipt, 'sequence' => $sequence], null, $at);
    }
    public function planPayment(int $node, array $accounts, int $obligation): array
    {
        $invoice = (new Query())->from('economy_obligation')->where(['id' => $obligation, 'budget_account_id' => $accounts['budget']['id']])->one($this->db);
        if (!$invoice) throw new GameError('OBLIGATION_NOT_FOUND', 'Обязательство не найдено.', 404);
        if ($invoice['status'] !== 'pending') throw new GameError('OBLIGATION_ALREADY_PAID', 'Обязательство уже оплачено.');
        $reservation = (new Query())->from('economy_budget_reservation')->where(['obligation_id' => $obligation, 'account_id' => $accounts['budget']['id'], 'released_at' => null])->one($this->db);
        $amount = $this->money($invoice['amount']); $budget = $this->account((int)$accounts['budget']['id']);
        if (!$reservation || $this->money($reservation['amount'])->compare($amount) !== 0 || $this->money($budget['reserved'])->compare($amount) < 0) throw new \RuntimeException('Obligation reservation mismatch.');
        $recipient = $this->account((int)$invoice['recipient_account_id']);
        $parent = (new Query())->select('node_id')->from('economy_subject')->where(['id' => $recipient['subject_id']])->scalar($this->db);
        if (!$parent || $recipient['role'] !== 'treasury') throw new \RuntimeException('Invalid obligation recipient.');
        // These rules apply only to this NEW receipt, not the amount or payee of the old invoice.
        $policy = $this->published((int)$parent);
        $this->money($recipient['amount'])->add($amount);
        return ['invoice' => $invoice, 'recipient' => $recipient, 'policy' => $policy, 'terms' => ['node_id' => $node, 'obligation_id' => $obligation,
            'payment_amount' => $amount->decimal(), 'parent_node_id' => (int)$parent, 'invoice_history_id' => (int)$invoice['history_id'],
            'recipient_policy_revision' => $policy['revision'], 'budget_after' => $this->money($budget['amount'])->subtract($amount)->decimal(),
            'reserved_after' => $this->money($budget['reserved'])->subtract($amount)->decimal(), 'destination' => 'parent_treasury']];
    }
    public function pay(array $accounts, array $plan, string $operation): void
    {
        $this->writable(); $invoice = $plan['invoice']; $amount = $this->money($invoice['amount']); $now = time();
        $budgetId = (int)$accounts['budget']['id']; $budget = $this->account($budgetId);
        $this->balance($budgetId, $this->money($budget['amount']), $this->money($budget['reserved'])->subtract($amount));
        $transfer = $this->transfer($budgetId, (int)$invoice['recipient_account_id'], $amount, $operation, 'parent-payment', 'parent_payment', 'Отчисление по обязательству #' . $invoice['id']);
        $this->receipt((int)$invoice['recipient_account_id'], $transfer, $amount, $plan['policy'], $now);
        $this->updateOne('economy_budget_reservation', ['released_at' => $now], ['obligation_id' => $invoice['id'], 'released_at' => null]);
        $this->updateOne('economy_obligation', ['status' => 'paid', 'paid_at' => $now, 'payment_transfer_id' => $transfer], ['id' => $invoice['id'], 'status' => 'pending']);
    }
    private function receipt(int $account, int $transfer, Money $amount, array $policy, int $now): void
    {
        $protection = $now + $policy['loss_policy']['protected_seconds'];
        $this->db->createCommand()->insert('economy_treasury_receipt', ['transfer_id' => $transfer, 'account_id' => $account, 'history_id' => $policy['history_id'],
            'original_amount' => $amount->decimal(), 'remaining_amount' => $amount->decimal(), 'received_at' => $now, 'protected_until' => $protection,
            'next_loss_at' => $protection + $policy['loss_policy']['loss_period_seconds'], 'loss_sequence' => 0, 'loss_fraction' => 0])->execute();
        $receipt = (int)$this->db->getLastInsertID();
        if ($policy['loss_policy']['loss_rate_bps'] > 0) $this->scheduleLoss($receipt, 1, $protection + $policy['loss_policy']['loss_period_seconds']);
    }
    /** Authorised market purchase, committed with the inventory movement by CommandBus. */
    public function receivePersonalPayment(int $user, int $recipientNode, Money $amount, string $operation, string $purpose): int
    {
        $this->writable();
        if ($amount->isNegative() || $amount->isZero()) throw new \LogicException('Positive payment required.');
        $policy = $this->published($recipientNode); $accounts = (new EconomyHierarchy($this->db))->accounts($recipientNode);
        $account = (new Locks($this->db))->row('economy_account', ['id' => $accounts['treasury']['id']]);
        $next = $this->money($account['amount'])->add($amount);
        $wallet = (new \common\services\user\CreditLedger($this->db))->changeExact($user, Money::parse('0')->subtract($amount)->decimal(), 'crop_purchase', $purpose);
        $this->balance((int)$account['id'], $next, $this->money($account['reserved']));
        $this->db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => 'harvest-purchase', 'kind' => 'crop_purchase', 'source_user_id' => $user, 'source_account_id' => null,
            'destination_account_id' => $account['id'], 'amount' => $amount->decimal(), 'source_after' => $wallet, 'destination_after' => $next->decimal(), 'purpose' => $purpose, 'created_at' => time()])->execute();
        $transfer = (int)$this->db->getLastInsertID();
        $this->receipt((int)$account['id'], $transfer, $amount, $policy, time());
        return $transfer;
    }
    /** Funded domain payment; caller checks the purpose, permission and budget commitment. */
    public function receiveBudgetPayment(int $budget, int $recipientNode, Money $amount, string $operation, string $purpose, string $kind = 'order_payment'): int
    {
        $this->writable();
        if (!in_array($kind, ['order_payment', 'crop_purchase', 'premises_purchase', 'garden_purchase', 'garden_expansion', 'equipment_expansion', 'warehouse_expansion', 'building_repair', 'map_cell_purchase'], true)) throw new \LogicException('Unsupported budget payment kind.');
        if ($this->account($budget)['role'] !== 'budget') throw new \LogicException('Expected a budget source.');
        $policy = $this->published($recipientNode); $accounts = (new EconomyHierarchy($this->db))->accounts($recipientNode);
        $transfer = $this->transfer($budget, (int)$accounts['treasury']['id'], $amount, $operation, 'budget-payment', $kind, $purpose);
        $this->receipt((int)$accounts['treasury']['id'], $transfer, $amount, $policy, time());
        return $transfer;
    }
}
