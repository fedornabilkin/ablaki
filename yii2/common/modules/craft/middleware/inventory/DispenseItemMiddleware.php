<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 16:52
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractMiddleware;
use common\modules\craft\models\CraftInventory;
use common\modules\craft\models\CraftItem;

/**
 * @property InventoryDataMiddleware $data
 */
class DispenseItemMiddleware extends AbstractMiddleware
{
    /** @var CraftItem */
    protected $item;

    public function check(): bool
    {
        $this->item = self::$data->getItem();

        $this->dispense();

        return parent::check();
    }

    public function dispense(): void
    {
        /** @var CraftInventory[] $slots */
        $slots = CraftInventory::find()
            ->andWhere(['user_id' => self::$data->user->user_id])
            ->andWhere(['or', ['item_id' => $this->item->id], ['item_id' => null]])
            ->all();

        $quantity = $this->item->getQuantity();
        $availableSum = $limit = 0;
        foreach ($slots as $slot) {
            $limit = $slot->slotMaxLimit();
            $available = $slot->slotMaxLimit() - $slot->item_quantity;
            if ($available > 0) {
                $availableSum += $available;
                if ($slot->item_id === null) {
                    $slot->item_id = $this->item->id;
                }
                self::$data->addInventorySlots($slot);
            }
        }

        $diff = $quantity - $availableSum;
        if ($diff > 0) {

            $newCount = ceil($diff / 100);
            for ($i = 1; $i <= $newCount; $i++) {
                $this->insertNext(new CreateSlotMiddleware());
            }

        }
    }

    public function available(CraftInventory $slot): int
    {
        return min($this->item->getQuantity(), $slot::SLOT_MAX_LIMIT - $slot->item_quantity);
    }

    protected function overflowSlot(CraftInventory $slot): bool
    {
        return ($this->item->getQuantity() + $slot->item_quantity) > $slot->slotMaxLimit();
    }

    protected function deficitSlot(CraftInventory $slot): bool
    {
        return ($slot->item_quantity - $this->item->getQuantity()) < 0;
    }
}
