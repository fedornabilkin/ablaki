<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Mandatory candidate fees use existing budget accounts and funding provenance. */
final class EconomyPayments
{
    public static function charge(Connection $db, int $user, int $payer, Money $amount, bool $personal, string $operation, string $purpose): void
    {
        if (!$db->getTransaction() || $amount->isNegative()) throw new \LogicException('Fee requires its command transaction.');
        if ($amount->isZero()) return;
        $settlement = (new Query())->select('n.id')->from(['c' => 'world_node_closure'])->innerJoin(['n' => 'world_node'], 'n.id=c.ancestor_id')
            ->where(['c.descendant_id' => $payer, 'n.hierarchy_level' => 3])->scalar($db);
        if (!$settlement) throw new GameError('SETTLEMENT_BUDGET_REQUIRED', 'Бюджет поселения ещё не подготовлен.', 503);
        $hierarchy = new EconomyHierarchy($db);
        $source = $hierarchy->provision($payer, $operation)['budget']; $target = $hierarchy->provision((int)$settlement, $operation)['budget'];
        if ($source['id'] === $target['id']) throw new GameError('INVALID_FEE_PAYER', 'Выберите собственный объект для оплаты.', 422);
        $ids = [(int)$source['id'], (int)$target['id']]; sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) (new Locks($db))->row('economy_account', ['id' => $id]);
        $spending = new BudgetSpending($db); $available = $spending->available((int)$source['id']);
        if ($available->compare($amount) < 0) {
            if (!$personal) throw new GameError('INSUFFICIENT_BUDGET', 'В бюджете недостаточно кредитов. Разрешите личный взнос недостающей суммы.', 422);
            (new BudgetFunding($db))->contribute($user, $payer, $amount->subtract($available), $purpose, $operation);
        }
        $source = (new Query())->from('economy_account')->where(['id' => $source['id']])->one($db);
        $target = (new Query())->from('economy_account')->where(['id' => $target['id']])->one($db);
        $allocations = $spending->allocation((int)$source['id'], $amount);
        $sourceAfter = Money::parse((string)$source['amount'])->subtract($amount);
        $targetAfter = Money::parse((string)$target['amount'])->add($amount);
        foreach ([[$source, $sourceAfter], [$target, $targetAfter]] as $entry) {
            if ($db->createCommand()->update('economy_account', ['amount' => $entry[1]->decimal(), 'revision' => new Expression('revision+1')], ['id' => $entry[0]['id'], 'revision' => $entry[0]['revision']])->execute() !== 1) throw new \RuntimeException('Fee account changed.');
        }
        $db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => 'mandatory_fee', 'kind' => 'mandatory_fee',
            'source_account_id' => $source['id'], 'destination_account_id' => $target['id'], 'amount' => $amount->decimal(),
            'source_after' => $sourceAfter->decimal(), 'destination_after' => $targetAfter->decimal(), 'purpose' => $purpose, 'created_at' => time()])->execute();
        $transfer = (int)$db->getLastInsertID();
        foreach ($allocations as $allocation) {
            $db->createCommand()->update('economy_funding_lot', ['remaining_amount' => $allocation['remaining_after']], ['id' => $allocation['id']])->execute();
            $db->createCommand()->insert('economy_spending_allocation', ['transfer_id' => $transfer, 'funding_lot_id' => $allocation['id'], 'amount' => $allocation['amount']])->execute();
        }
    }
}
