<?php
namespace common\modules\world\modules\craft\domain;

/** Pure placement plan. Row order is significant: callers supply their accepted stack priority. */
final class InventoryPlacement
{
    /**
     * @param array $rows Inventory snapshots with id, item_id, item_quantity and slot.
     * @return array{grant: array, missing_quantity: int}
     */
    public static function plan(
        array $rows,
        int $capacity,
        int $itemId,
        int $quantity,
        int $stackSize,
        bool $mergeExisting = true
    ): array {
        if ($quantity < 0 || $capacity < 0 || $stackSize < 1 || $stackSize > 10000) {
            throw new \InvalidArgumentException('Invalid inventory placement bounds.');
        }
        $remaining = $quantity;
        $grants = [];
        $occupied = [];
        foreach ($rows as $row) {
            if ((int)$row['item_quantity'] < 1) {
                continue;
            }
            $position = (int)$row['slot'];
            $occupied[$position] = true;
            if (!$mergeExisting || $position > $capacity || (int)$row['item_id'] !== $itemId) {
                continue;
            }
            $add = min($remaining, max(0, $stackSize - (int)$row['item_quantity']));
            if ($add === 0) {
                continue;
            }
            $grants[] = ['inventory_id' => (int)$row['id'], 'position' => $position, 'quantity' => $add];
            $remaining -= $add;
        }
        for ($position = 1; $position <= $capacity && $remaining > 0; $position++) {
            if (isset($occupied[$position])) {
                continue;
            }
            $add = min($remaining, $stackSize);
            $grants[] = ['inventory_id' => null, 'position' => $position, 'quantity' => $add];
            $remaining -= $add;
        }
        return ['grant' => $grants, 'missing_quantity' => $remaining];
    }
}
