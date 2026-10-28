<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;
use yii\web\UnprocessableEntityHttpException;

/** Validate before reserving funds; a supported stake must also have a payable commission. */
class LegacyCreditPolicy
{
    public static function amount($value, int $commissionDivisor = 1): Money
    {
        try {
            if (!is_numeric($value)) throw new \InvalidArgumentException();
            $amount = Money::fromLegacy((float)$value);
            if ($amount->isNegative() || $amount->isZero()) throw new \InvalidArgumentException();
            $amount->divideExact($commissionDivisor);
            return $amount;
        } catch (\InvalidArgumentException | \OverflowException $e) {
            throw new UnprocessableEntityHttpException('Сумма или её комиссия не представима с точностью 0,0001 Cr.');
        }
    }
    public static function game($value): Money { return self::amount($value, 10); }
    public static function exchange($value): Money { return self::amount($value, 20); }
}
