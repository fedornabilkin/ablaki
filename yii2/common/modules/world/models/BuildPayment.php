<?php
namespace common\modules\world\models;

use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\value\Money;
use common\services\user\CreditLedger;
use yii\db\Expression;

/** The maximum wage is reserved at start. Owner time costs zero; unspent reserve is released. */
final class BuildPayment
{
    public static function settle(Build $build, string $operation): void
    {
        if (!$build->commitment_id) return;
        $db = Build::getDb(); $spending = new BudgetSpending($db); $hold = $spending->commitment((int)$build->commitment_id);
        $account = $spending->account((int)$hold['account_id']);
        $total = Money::parse('0'); $budget = Money::parse($build->labor_budget);
        $works = $build->getWork()->orderBy(['user_id' => SORT_ASC, 'id' => SORT_ASC])->all();
        $seconds = []; foreach ($works as $work) if ((int)$work->user_id !== (int)$build->owner_user_id)
            $seconds[$work->user_id] = ($seconds[$work->user_id] ?? 0) + (int)$work->seconds;
        foreach ($seconds as $user => $time) {
            $amount = $budget->ratioFloor($time, (int)$build->required_seconds);
            if ($amount->isZero()) continue;
            $allocations = $spending->allocation((int)$account['id'], $amount);
            $balance = Money::parse((string)$account['amount'])->subtract($amount);
            $reserved = Money::parse((string)$account['reserved'])->subtract($amount);
            if ($reserved->isNegative() || $balance->compare($reserved) < 0) throw new \RuntimeException('Invalid construction reserve.');
            $wallet = (new CreditLedger($db))->changeExact((int)$user, $amount->decimal(), 'world_build', 'Строительство объекта #' . $build->node_id);
            if ($db->createCommand()->update('economy_account', ['amount' => $balance->decimal(), 'reserved' => $reserved->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $account['id']])->execute() !== 1) throw new \RuntimeException('Payroll debit failed.');
            $db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => 'builder-' . $user, 'kind' => 'construction_wage',
                'source_account_id' => $account['id'], 'destination_user_id' => $user, 'destination_account_id' => null,
                'amount' => $amount->decimal(), 'source_after' => $balance->decimal(), 'destination_after' => $wallet,
                'purpose' => 'Строительство #' . $build->id, 'created_at' => time()])->execute(); $transfer = (int)$db->getLastInsertID();
            foreach ($allocations as $lot) {
                $db->createCommand()->update('economy_funding_lot', ['remaining_amount' => $lot['remaining_after']], ['id' => $lot['id']])->execute();
                $db->createCommand()->insert('economy_spending_allocation', ['transfer_id' => $transfer, 'funding_lot_id' => $lot['id'], 'amount' => $lot['amount']])->execute();
            }
            // One payment per builder, even when the builder starts several work sessions.
            $first = BuildWork::find()->where(['build_id' => $build->id, 'user_id' => $user])->orderBy('id')->one();
            $first->paid_amount = $amount->decimal(); if (!$first->save(false)) throw new \RuntimeException('Payroll receipt failed.');
            $account['amount'] = $balance->decimal(); $account['reserved'] = $reserved->decimal(); $total = $total->add($amount);
        }
        $left = Money::parse((string)$hold['remaining_amount'])->subtract($total);
        if ($left->isNegative()) throw new \RuntimeException('Wages exceed the reserve.');
        $db->createCommand()->update('economy_spending_commitment', ['remaining_amount' => $left->decimal()], ['id' => $hold['id']])->execute();
        $spending->release((int)$hold['id']);
    }
}
