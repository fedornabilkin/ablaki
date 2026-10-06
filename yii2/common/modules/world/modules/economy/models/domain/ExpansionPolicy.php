<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;

class ExpansionPolicy
{
    public function quote(int $initial, int $unlocked, int $limit, string $basePrice, int $quantity, string $curve = 'linear'): array
    {
        if ($initial < 0 || $unlocked < $initial || $limit < $unlocked || $limit > 1000000 || $quantity < 1 || $quantity > 100 || $quantity > $limit - $unlocked || !in_array($curve, ['linear', 'progressive'], true)) throw new GameError('EXPANSION_LIMIT', 'Недопустимое количество новых мест.', 422);
        $base = Money::parse($basePrice);
        if ($base->isNegative() || $base->isZero()) throw new GameError('INVALID_PRICE', 'Цена расширения должна быть положительной.', 422);
        $grossTotal = Money::parse('0'); $prices = [];
        $price = $base;
        for ($ordinal = $initial + 1; $ordinal <= $unlocked; $ordinal++) {
            if ($curve === 'progressive') $price = $price->add($price->ratioCeil(1, 5));
            else $price = $base->multiply($ordinal - $initial);
        }
        for ($offset = 1; $offset <= $quantity; $offset++) {
            if ($curve === 'linear') $price = $base->multiply($unlocked - $initial + $offset);
            $grossTotal = $grossTotal->add($price);
            $prices[] = ['ordinal' => $unlocked + $offset, 'price' => $price];
            if ($curve === 'progressive') $price = $price->add($price->ratioCeil(1, 5));
        }
        // System expansions receive a 5% package discount from three places onward.
        // Carry fractional units between rows so unit prices add up to the exact discounted total.
        $discountBps = $quantity >= 3 ? 500 : 0; $discount = Money::parse('0'); $carry = 0;
        foreach ($prices as &$unit) {
            $price = $unit['price'];
            $part = $price->portion($discountBps, $carry); $carry = $part['carry'];
            $discount = $discount->add($part['amount']); $unit['price'] = $price->subtract($part['amount'])->decimal();
            $unit['gross_price'] = $price->decimal();
        }
        unset($unit);
        $total = $grossTotal->subtract($discount);
        return ['initial_open' => $initial, 'unlocked' => $unlocked, 'limit' => $limit, 'quantity' => $quantity, 'unit_prices' => $prices,
            'gross_total' => $grossTotal->decimal(), 'discount_bps' => $discountBps, 'discount_amount' => $discount->decimal(), 'total' => $total->decimal(), 'currency' => 'Cr', 'curve' => $curve];
    }
}
