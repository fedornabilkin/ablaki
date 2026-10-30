<?php
namespace common\modules\world\models;

use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\value\Money;
use common\services\user\CreditLedger;
use yii\db\Expression;
use yii\db\Query;

/** The maximum wage is reserved at start. Owner time is free; unused reserve is released. */
final class BuildPayment
{
    public static function settle(Build $build, string $operation): void
    {
        if (!$build->commitment_id) {
            return;
        }
        $db = Build::getDb();
        $spending = new BudgetSpending($db);
        $hold = $spending->commitment((int)$build->commitment_id);
        $account = $spending->account((int)$hold['account_id']);
        $total = Money::parse('0');
        $budget = Money::parse($build->labor_budget);

        foreach (self::helperSeconds($build) as $user => $seconds) {
            $amount = $budget->ratioFloor($seconds, (int)$build->required_seconds);
            if ($amount->isZero()) {
                continue;
            }
            $allocations = $spending->allocation((int)$account['id'], $amount);
            $balance = Money::parse((string)$account['amount'])->subtract($amount);
            $reserved = Money::parse((string)$account['reserved'])->subtract($amount);
            if ($reserved->isNegative() || $balance->compare($reserved) < 0) {
                throw new \RuntimeException('Invalid construction reserve.');
            }
            $wallet = (new CreditLedger($db))->changeExact(
                (int)$user,
                $amount->decimal(),
                $build->isRepair() ? 'world_repair' : 'world_build',
                ($build->isRepair() ? 'Ремонт объекта #' : 'Строительство объекта #') . $build->node_id
            );
            $written = $db->createCommand()->update('economy_account', [
                'amount' => $balance->decimal(),
                'reserved' => $reserved->decimal(),
                'revision' => new Expression('[[revision]]+1'),
            ], ['id' => $account['id']])->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Payroll debit failed.');
            }
            $written = $db->createCommand()->insert('economy_transfer', [
                'operation_id' => $operation,
                'line_code' => 'builder-' . $user,
                'kind' => $build->isRepair() ? 'repair_wage' : 'construction_wage',
                'source_account_id' => $account['id'],
                'destination_user_id' => $user,
                'destination_account_id' => null,
                'amount' => $amount->decimal(),
                'source_after' => $balance->decimal(),
                'destination_after' => $wallet,
                'purpose' => ($build->isRepair() ? 'Ремонт #' : 'Строительство #') . $build->id,
                'created_at' => time(),
            ])->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Payroll transfer write failed.');
            }
            $spending->recordAllocation((int)$db->getLastInsertID(), $allocations);

            // One receipt per builder, even across multiple work sessions.
            $first = BuildWork::find()
                ->where(['build_id' => $build->id, 'user_id' => $user])
                ->orderBy('id')
                ->one();
            if (!$first) {
                throw new \RuntimeException('Payroll work session missing.');
            }
            $first->paid_amount = $amount->decimal();
            if (!$first->save(false)) {
                throw new \RuntimeException('Payroll receipt failed.');
            }
            $account['amount'] = $balance->decimal();
            $account['reserved'] = $reserved->decimal();
            $total = $total->add($amount);
        }

        $left = Money::parse((string)$hold['remaining_amount'])->subtract($total);
        if ($left->isNegative()) {
            throw new \RuntimeException('Wages exceed the reserve.');
        }
        if (!$total->isZero()) {
            $written = $db->createCommand()->update(
                'economy_spending_commitment',
                ['remaining_amount' => $left->decimal()],
                ['id' => $hold['id']]
            )->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Payroll commitment update failed.');
            }
        }
        $spending->release((int)$hold['id']);
    }

    private static function helperSeconds(Build $build): array
    {
        $seconds = [];
        $works = $build->getWork()->orderBy(['user_id' => SORT_ASC, 'id' => SORT_ASC])->all();
        foreach ($works as $work) {
            if ((int)$work->user_id !== (int)$build->owner_user_id) {
                $seconds[$work->user_id] = ($seconds[$work->user_id] ?? 0) + (int)$work->seconds;
            }
        }
        return $seconds;
    }

    /** Only an unfinished object's owner-funded balance is returned on cancellation. */
    public static function refundCancelled(Build $build, string $operation): void
    {
        $db = Build::getDb();
        $accounts = (new EconomyHierarchy($db))->accounts((int)$build->node_id);
        if (empty($accounts['budget'])) {
            return;
        }
        $account = (new BudgetSpending($db))->account((int)$accounts['budget']['id']);
        $starter = $db->schema->getTableSchema('world_starter_funding')
            && (new Query())->from('world_starter_funding')->where(['destination_node_id' => $build->node_id])->exists($db);
        $amount = self::refundableAmount($build, $account, $starter);
        if ($amount->isZero()) {
            return;
        }
        if (!Money::parse((string)$account['reserved'])->isZero()) {
            throw new \RuntimeException('Cancelled construction still has reserved funds.');
        }

        $wallet = (new CreditLedger($db))->changeExact(
            (int)$build->owner_user_id,
            $amount->decimal(),
            'world_build_refund',
            'Возврат остатка стройки #' . $build->id
        );
        $after = Money::parse((string)$account['amount'])->subtract($amount)->decimal();
        $written = $db->createCommand()->update('economy_account', [
            'amount' => $after,
            'revision' => new Expression('[[revision]]+1'),
        ], ['id' => $account['id']])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Construction refund debit failed.');
        }
        // There may be no personal funding lots when this balance is earned income.
        $db->createCommand()->update(
            'economy_funding_lot',
            ['remaining_amount' => '0.0000'],
            ['budget_account_id' => $account['id']] + ($starter ? ['investor_user_id' => $build->owner_user_id] : [])
        )->execute();
        $written = $db->createCommand()->insert('economy_transfer', [
            'operation_id' => $operation,
            'line_code' => 'construction-refund',
            'kind' => 'construction_refund',
            'source_account_id' => $account['id'],
            'destination_user_id' => $build->owner_user_id,
            'destination_account_id' => null,
            'amount' => $amount->decimal(),
            'source_after' => $after,
            'destination_after' => $wallet,
            'purpose' => 'Возврат остатка отменённой стройки',
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Construction refund receipt failed.');
        }
    }

    private static function refundableAmount(Build $build, array $account, bool $starter): Money
    {
        $amount = Money::parse((string)$account['amount']);
        if (!$starter) {
            return $amount;
        }
        $personal = Money::parse('0');
        $lots = (new Query())
            ->select('remaining_amount')
            ->from('economy_funding_lot')
            ->where(['budget_account_id' => $account['id'], 'investor_user_id' => $build->owner_user_id])
            ->column(Build::getDb());
        foreach ($lots as $lot) {
            $personal = $personal->add(Money::parse((string)$lot));
        }
        return $amount->compare($personal) > 0 ? $personal : $amount;
    }
}
