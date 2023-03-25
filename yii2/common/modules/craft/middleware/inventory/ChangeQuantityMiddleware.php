<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 20:35
 */

namespace common\modules\craft\middleware\inventory;

use common\modules\craft\models\CraftItem;

/**
 * @property InventoryDataMiddleware $dataInventory
 */
class ChangeQuantityMiddleware extends AbstractInventoryMiddleware
{
    /** @var CraftItem */
    protected $item;

    public function check(): bool
    {
        $this->item = self::$dataInventory->getItem();

        $this->dispense();

        return parent::check();
    }

    /**
     * @return void
     */
    private function dispense(): void
    {
        foreach (self::$dataInventory->getInventorySlots() as $slot) {

            $quantity = $this->item->getQuantity();
            $available = $slot->slotAvailable($quantity);
            $stock = $slot->slotStock(abs($quantity));

            // добавляем предметы в слот и уменьшаем количество в предмете
            if ($quantity > 0 && $available > 0) {
                $slot->changeItemQuantity($available);
                $this->item->setQuantity($quantity - $available);
                $slot->save();
            }

            // убираем предметы из слота и увеличиваем отрицательное значение в предмете
            if ($quantity < 0 && $stock > 0) {
                $slot->changeItemQuantity(0 - $stock);
                $this->item->setQuantity($quantity + $stock);
                $slot->save();
            }

            if ($this->item->getQuantity() === 0) {
                break;
            }

        }
    }

}
