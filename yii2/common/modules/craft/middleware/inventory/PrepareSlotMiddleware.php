<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 16:52
 */

namespace common\modules\craft\middleware\inventory;

use common\modules\craft\models\CraftInventory;
use common\modules\craft\models\CraftItem;

/**
 * @property InventoryDataMiddleware $dataInventory
 */
class PrepareSlotMiddleware extends AbstractInventoryMiddleware
{
    /** @var CraftItem */
    protected $item;

    /** @var CraftInventory[] */
    protected $slots = [];

    public function check(): bool
    {
        $this->item = self::$dataInventory->getItem();

        $this->execute();

        return parent::check();
    }

    public function execute(): void
    {
        $itemQuantity = $this->item->getQuantity();

        $sort = $itemQuantity < 0 ? SORT_ASC : SORT_DESC;
        $slotsFull = CraftInventory::find()
            ->andWhere(['user_id' => self::$dataInventory->user->user_id])
            ->andWhere(['item_id' => $this->item->id])
            ->orderBy(['item_quantity' => $sort])
            ->all();

        $slotsEmpty = CraftInventory::find()
            ->andWhere(['user_id' => self::$dataInventory->user->user_id])
            ->andWhere(['item_id' => null])
            ->orderBy(['slot' => SORT_ASC])
            ->all();

        $this->slots = array_merge($slotsFull, $slotsEmpty);

        $availableSum = $allQuantity = 0;

        foreach ($this->slots as $slot) {
            $allQuantity += $slot->item_quantity;
            $available = $slot->slotAvailable();

            if ($available > 0) {
                $availableSum += $available;
                if ($slot->item_id === null) {
                    $slot->item_id = $this->item->id;
                }
            }

            self::$dataInventory->addInventorySlots($slot);
        }

        if ($itemQuantity > 0) {
            $diff = $itemQuantity - $availableSum;
            if ($diff > 0) {

                $newCount = ceil($diff / 100);
                for ($i = 1; $i <= $newCount; $i++) {
                    $this->insertNext(new CreateSlotMiddleware());
                }

            }
        }

        if ($itemQuantity < 0 && abs($itemQuantity) > $allQuantity) {
            $this->consoleLog(['no items in inventory']);
        }
    }
}
