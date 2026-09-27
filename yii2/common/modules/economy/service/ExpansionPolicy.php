<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\services\game\GameError;

class ExpansionPolicy
{
    public function quote(int $initial, int $unlocked, int $limit, string $basePrice, int $quantity): array
    {
        if ($initial < 0 || $unlocked < $initial || $limit < $unlocked || $limit > 10000 || $quantity < 1 || $quantity > 100 || $quantity > $limit - $unlocked) throw new GameError('EXPANSION_LIMIT', 'Недопустимое количество новых мест.', 422);
        $base = Money::parse($basePrice);
        if ($base->isNegative() || $base->isZero()) throw new GameError('INVALID_PRICE', 'Цена расширения должна быть положительной.', 422);
        $total = Money::parse('0'); $prices = [];
        for ($offset = 1; $offset <= $quantity; $offset++) {
            $price = $base->multiply($unlocked - $initial + $offset); $total = $total->add($price);
            $prices[] = ['ordinal' => $unlocked + $offset, 'price' => $price->decimal()];
        }
        return ['initial_open' => $initial, 'unlocked' => $unlocked, 'limit' => $limit, 'quantity' => $quantity, 'unit_prices' => $prices, 'total' => $total->decimal(), 'currency' => 'Cr', 'curve' => 'linear'];
    }
}
