<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\services\game\GameError;

class ExpansionPolicy
{
    public function quote(int $initial, int $unlocked, int $limit, string $basePrice, int $quantity, string $curve = 'linear'): array
    {
        if ($initial < 0 || $unlocked < $initial || $limit < $unlocked || $limit > 10000 || $quantity < 1 || $quantity > 100 || $quantity > $limit - $unlocked || !in_array($curve, ['linear', 'progressive'], true)) throw new GameError('EXPANSION_LIMIT', 'Недопустимое количество новых мест.', 422);
        $base = Money::parse($basePrice);
        if ($base->isNegative() || $base->isZero()) throw new GameError('INVALID_PRICE', 'Цена расширения должна быть положительной.', 422);
        $total = Money::parse('0'); $prices = [];
        $price = $base;
        for ($ordinal = $initial + 1; $ordinal <= $unlocked; $ordinal++) {
            if ($curve === 'progressive') $price = $price->add($price->ratioCeil(1, 5));
            else $price = $base->multiply($ordinal - $initial);
        }
        for ($offset = 1; $offset <= $quantity; $offset++) {
            if ($curve === 'linear') $price = $base->multiply($unlocked - $initial + $offset);
            $total = $total->add($price);
            $prices[] = ['ordinal' => $unlocked + $offset, 'price' => $price->decimal()];
            if ($curve === 'progressive') $price = $price->add($price->ratioCeil(1, 5));
        }
        return ['initial_open' => $initial, 'unlocked' => $unlocked, 'limit' => $limit, 'quantity' => $quantity, 'unit_prices' => $prices, 'total' => $total->decimal(), 'currency' => 'Cr', 'curve' => $curve];
    }
}
