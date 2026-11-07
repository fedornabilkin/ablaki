<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\value\Money;

/** Independent, exact price calculation. Every fee remains an explicit ledger line. */
final class MarketPricing
{
    public static function validate(array $policy): void
    {
        foreach (['delivery_bps', 'city_bps', 'owner_bps', 'bid_bps', 'sell_burn_bps'] as $key) {
            if (!is_int($policy[$key] ?? null) || $policy[$key] < 1 || $policy[$key] > 10000) throw new \InvalidArgumentException('Invalid market rate: ' . $key);
        }
        foreach (['refill_seconds', 'stock_per_item', 'capacity', 'max_quantity', 'buy_daily_operations'] as $key) {
            if (!is_int($policy[$key] ?? null) || $policy[$key] < 1 || $policy[$key] > 86400) throw new \InvalidArgumentException('Invalid market limit: ' . $key);
        }
        if ($policy['capacity'] > 200 || $policy['max_quantity'] > 100) throw new \InvalidArgumentException('Market capacity exceeded.');
        foreach (['bot_reserve_cr', 'buy_daily_cr'] as $key) {
            if (Money::parse($policy[$key])->isNegative()) throw new \InvalidArgumentException('Negative market amount.');
        }
    }

    public static function unit(string $reference, array $policy): array
    {
        $base = Money::parse($reference);
        if ($base->isNegative() || $base->isZero()) throw new \InvalidArgumentException('Positive reference price required.');
        $delivery = $base->ratioCeil($policy['delivery_bps'], 10000);
        $city = $base->ratioCeil($policy['city_bps'], 10000);
        $owner = $base->ratioCeil($policy['owner_bps'], 10000);
        $bid = $base->ratioFloor($policy['bid_bps'], 10000);
        $burn = $bid->ratioCeil($policy['sell_burn_bps'], 10000);
        return ['base_cr' => $base->decimal(), 'delivery_cr' => $delivery->decimal(), 'city_cr' => $city->decimal(), 'owner_cr' => $owner->decimal(),
            'buy_cr' => $base->add($delivery)->add($city)->add($owner)->decimal(), 'bid_cr' => $bid->decimal(),
            'sell_burn_cr' => $burn->decimal(), 'sell_cr' => $bid->subtract($burn)->decimal()];
    }
}
