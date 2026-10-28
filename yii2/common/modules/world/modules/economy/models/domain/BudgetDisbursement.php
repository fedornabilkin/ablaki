<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use common\services\user\CreditLedger;
use yii\db\Connection;
use yii\db\Expression;

/** Internal authorised payouts only; the caller supplies its accepted domain obligation. */
final class BudgetDisbursement
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function debit(int $account, Money $amount, string $operation, string $line, string $kind, string $purpose, ?int $target, ?int $user, ?int $commitment): int
    {
        $db = $this->db;
        if (!$db->getTransaction() || $amount->isZero() || $amount->isNegative()) throw new \LogicException('A positive accepted budget payout requires a transaction.');
        WalletSchema::requireReady($db); (new Locks($db))->row('world_registry', ['id' => 1]);
        $spending = new BudgetSpending($db); $source = $spending->account($account); $reserved = Money::parse((string)$source['reserved']);
        $hold = $commitment ? $spending->commitment($commitment) : null;
        if ($hold) {
            if ((int)$hold['account_id'] !== $account || Money::parse((string)$hold['remaining_amount'])->compare($amount) < 0) throw new GameError('WAGE_RESERVE_UNAVAILABLE', 'Резерв оплаты партии недоступен.');
            $reserved = $reserved->subtract($amount);
        } elseif ($spending->available($account)->compare($amount) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'В бюджете недостаточно свободных средств.');
        $after = Money::parse((string)$source['amount'])->subtract($amount);
        if ($reserved->isNegative() || $after->compare($reserved) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Резерв бюджета изменился.');
        $allocations = $spending->allocation($account, $amount);
        if ($target) {
            $recipient = $spending->account($target); $destination = Money::parse((string)$recipient['amount'])->add($amount)->decimal();
            if ($db->createCommand()->update('economy_account', ['amount' => $destination, 'revision' => new Expression('[[revision]]+1')], ['id' => $target, 'revision' => $recipient['revision']])->execute() !== 1) throw new \RuntimeException('Budget recipient changed.');
        } else $destination = (new CreditLedger($db))->changeExact($user, $amount->decimal(), $kind, $purpose);
        if ($db->createCommand()->update('economy_account', ['amount' => $after->decimal(), 'reserved' => $reserved->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $account, 'revision' => $source['revision']])->execute() !== 1) throw new \RuntimeException('Budget source changed.');
        $db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => $line, 'kind' => $kind,
            'source_account_id' => $account, 'destination_account_id' => $target, 'destination_user_id' => $user,
            'amount' => $amount->decimal(), 'source_after' => $after->decimal(), 'destination_after' => $destination, 'purpose' => $purpose, 'created_at' => time()])->execute();
        $transfer = (int)$db->getLastInsertID();
        foreach ($allocations as $lot) {
            if ($db->createCommand()->update('economy_funding_lot', ['remaining_amount' => $lot['remaining_after']], ['id' => $lot['id']])->execute() !== 1) throw new \RuntimeException('Payout funding changed.');
            $db->createCommand()->insert('economy_spending_allocation', ['transfer_id' => $transfer, 'funding_lot_id' => $lot['id'], 'amount' => $lot['amount']])->execute();
        }
        if ($hold) {
            $left = Money::parse((string)$hold['remaining_amount'])->subtract($amount);
            if ($db->createCommand()->update('economy_spending_commitment', ['remaining_amount' => $left->decimal(), 'closed_at' => $left->isZero() ? time() : null], ['id' => $commitment, 'closed_at' => null])->execute() !== 1) throw new \RuntimeException('Payout commitment changed.');
        }
        return $transfer;
    }
    public function toBudget(int $source, int $destination, Money $amount, string $operation, string $line, string $purpose, ?int $commitment = null, string $kind = 'starter_funding'): int
    {
        if ($source === $destination) throw new \LogicException('Distinct budgets required.');
        return $this->debit($source, $amount, $operation, $line, $kind, $purpose, $destination, null, $commitment);
    }
    public function wage(int $source, int $user, Money $amount, string $operation, string $line, string $purpose, ?int $commitment = null): int
    {
        return $this->debit($source, $amount, $operation, $line, 'resource_wage', $purpose, null, $user, $commitment);
    }
}
