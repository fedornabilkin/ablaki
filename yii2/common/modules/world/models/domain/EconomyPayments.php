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
        if (!$db->getTransaction() || $amount->isNegative()) {
            throw new \LogicException('Fee requires its command transaction.');
        }
        if ($amount->isZero()) {
            return;
        }
        $settlement = (new Query())
            ->select('n.id')
            ->from(['c' => 'world_node_closure'])
            ->innerJoin(['n' => 'world_node'], 'n.id=c.ancestor_id')
            ->where(['c.descendant_id' => $payer, 'n.hierarchy_level' => 3])
            ->scalar($db);
        if (!$settlement) {
            throw new GameError('SETTLEMENT_BUDGET_REQUIRED', 'Бюджет поселения ещё не подготовлен.', 503);
        }
        $hierarchy = new EconomyHierarchy($db);
        $source = $hierarchy->provision($payer, $operation)['budget'];
        $target = $hierarchy->provision((int)$settlement, $operation)['budget'];
        if ($source['id'] === $target['id']) {
            throw new GameError('INVALID_FEE_PAYER', 'Выберите собственный объект для оплаты.', 422);
        }
        $ids = [(int)$source['id'], (int)$target['id']];
        sort($ids, SORT_NUMERIC);
        $locks = new Locks($db);
        foreach ($ids as $id) {
            $locks->row('economy_account', ['id' => $id]);
        }

        $spending = new BudgetSpending($db);
        $available = $spending->available((int)$source['id']);
        if ($available->compare($amount) < 0) {
            if (!$personal) {
                throw new GameError('INSUFFICIENT_BUDGET', 'В бюджете недостаточно кредитов. Разрешите личный взнос недостающей суммы.', 422);
            }
            (new BudgetFunding($db))->contribute($user, $payer, $amount->subtract($available), $purpose, $operation);
        }

        // A contribution can also settle starter funding, so reread both balances.
        $source = (new Query())->from('economy_account')->where(['id' => $source['id']])->one($db);
        $target = (new Query())->from('economy_account')->where(['id' => $target['id']])->one($db);
        $allocations = $spending->allocation((int)$source['id'], $amount);
        $sourceAfter = Money::parse((string)$source['amount'])->subtract($amount);
        $targetAfter = Money::parse((string)$target['amount'])->add($amount);
        foreach ([[$source, $sourceAfter], [$target, $targetAfter]] as [$account, $after]) {
            $written = $db->createCommand()->update('economy_account', [
                'amount' => $after->decimal(),
                'revision' => new Expression('[[revision]]+1'),
            ], ['id' => $account['id'], 'revision' => $account['revision']])->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Fee account changed.');
            }
        }
        $written = $db->createCommand()->insert('economy_transfer', [
            'operation_id' => $operation,
            'line_code' => 'mandatory_fee',
            'kind' => 'mandatory_fee',
            'source_account_id' => $source['id'],
            'destination_account_id' => $target['id'],
            'amount' => $amount->decimal(),
            'source_after' => $sourceAfter->decimal(),
            'destination_after' => $targetAfter->decimal(),
            'purpose' => $purpose,
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Fee transfer write failed.');
        }
        $spending->recordAllocation((int)$db->getLastInsertID(), $allocations);
    }
}