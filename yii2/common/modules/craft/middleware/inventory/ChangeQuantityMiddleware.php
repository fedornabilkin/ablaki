<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 20:35
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractMiddleware;
use common\modules\craft\models\CraftInventory;
use common\modules\craft\models\CraftItem;

/**
 * @property InventoryDataMiddleware $data
 */
class ChangeQuantityMiddleware extends AbstractMiddleware
{
    /** @var CraftItem */
    protected $item;

    public function check(): bool
    {
        $this->item = self::$data->getItem();

        $this->dispense();

        return parent::check();
    }

    /**
     * @return void
     */
    private function dispense(): void
    {
        foreach (self::$data->getInventorySlots() as $slot) {

            $available = $this->available($slot);

            if ($available > 0) {
                $slot->changeItemQuantity($available);
                $this->item->setQuantity($this->item->getQuantity() - $available);
                $slot->save();
            }

            if ($this->item->getQuantity() === 0) {
                break;
            }

        }
    }

    public function available(CraftInventory $slot): int
    {
        return min($this->item->getQuantity(), $slot->slotMaxLimit() - $slot->item_quantity);
    }
}
