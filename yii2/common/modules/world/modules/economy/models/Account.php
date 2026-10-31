<?php
namespace common\modules\world\modules\economy\models;

use common\modules\world\models\Node;
use common\modules\world\models\Record;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\value\Money;

class Account extends Record
{
    public static function tableName() { return 'economy_account'; }
    public static function state(int $node, int $user): array
    {
        self::financialOwner($node, $user);
        $accounts = (new EconomyHierarchy(self::getDb()))->accounts($node); $budget = $accounts['budget'] ?? ['amount' => '0', 'reserved' => '0'];
        $amount = Money::parse((string)$budget['amount']); $reserved = Money::parse((string)$budget['reserved']);
        return ['amount' => $amount->decimal(), 'reserved' => $reserved->decimal(), 'available' => $amount->subtract($reserved)->decimal()];
    }
    public static function fund(int $node, int $user, string $amount, string $operation): array
    {
        self::financialOwner($node, $user);
        $money = Money::parse($amount);
        if ($money->isZero() || $money->isNegative()) throw new \common\modules\world\support\GameError('INVALID_AMOUNT', 'Введите положительную сумму.', 422);
        $result = (new BudgetFunding(self::getDb()))->contribute($user, $node, $money, 'Бюджет объекта', $operation);
        return self::state($node, $user) + $result + ['contributed_cr' => $money->decimal(), 'message' => 'Бюджет пополнен.'];
    }
    private static function financialOwner(int $node, int $user): void
    {
        $place = Node::readable($node, $user); $place->requireOwner($user);
        if (\common\modules\world\domain\NodeStructure::isLeaf($place->getAttributes()))
            throw new \common\modules\world\support\GameError('PARENT_BUDGET_REQUIRED', 'У листового объекта нет собственного бюджета. Откройте родительский объект.', 422);
    }
}
