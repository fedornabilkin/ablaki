<?php
namespace common\modules\world\modules\craft\domain;

/** Backpack rules operate on snapshots and explicit time, without storage or framework state. */
final class BackpackLayout
{
    public const BASE = 20;
    public const LIMIT = 50;

    public static function capacity(?int $permanent, array $leases, int $now): array
    {
        $permanent = max(self::BASE, min(self::LIMIT, $permanent ?? self::BASE));
        $temporary = 0;
        $expires = null;
        foreach ($leases as $lease) {
            if ((int)$lease['expires_at'] > $now) {
                $temporary += (int)$lease['slots'];
                $expires = $expires === null ? (int)$lease['expires_at'] : min($expires, (int)$lease['expires_at']);
            }
        }
        return [
            'permanent_slots' => $permanent,
            'active_slots' => min(self::LIMIT, $permanent + $temporary),
            'slots_expire_at' => $expires,
        ];
    }

    /** Keep every item when leases expire; overflow remains visible and can refill freed cells. */
    public static function project(array $rows, int $active): array
    {
        $occupied = [];
        $held = [];
        foreach ($rows as $slot) {
            if (!$slot['item_id'] || (int)$slot['item_quantity'] < 1) {
                continue;
            }
            $index = (int)$slot['slot'];
            if ($index > 0 && $index <= $active && !isset($occupied[$index])) {
                $occupied[$index] = $slot;
            } else {
                $held[] = $slot;
            }
        }
        foreach ($held as $slot) {
            $free = null;
            for ($position = 1; $position <= $active; $position++) {
                if (!isset($occupied[$position])) {
                    $free = $position;
                    break;
                }
            }
            $index = $free ?? max($active + 1, (int)$slot['slot']);
            while (isset($occupied[$index])) {
                $index++;
            }
            $slot['slot'] = $index;
            $occupied[$index] = $slot;
        }
        ksort($occupied);
        return array_values($occupied);
    }

    public static function purchaseQuote(int $permanent, int $quantity, int $basePrice): array
    {
        if ($quantity < 1 || $quantity > self::LIMIT) {
            throw new \DomainException('Некорректное количество слотов.');
        }
        $next = $permanent + $quantity;
        if ($next > self::LIMIT) {
            throw new \DomainException('Все постоянные слоты уже открыты.');
        }
        $ordinal = $permanent - self::BASE + 1;
        return [
            'unit_price' => $basePrice * $ordinal,
            'total_price' => $basePrice * intdiv($quantity * (2 * $ordinal + $quantity - 1), 2),
            'capacity_before' => $permanent,
            'capacity_after' => $next,
        ];
    }
}
