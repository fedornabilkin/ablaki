<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.03.2023
 * Time: 19:39
 */

namespace common\modules\craft\middleware\inventory;

use common\modules\craft\models\CraftInventory;

/**
 * @property InventoryDataMiddleware $dataInventory
 */
class CreateSlotMiddleware extends AbstractInventoryMiddleware
{
    public function check(): bool
    {
        $item = self::$dataInventory->getItem();
        $userId = self::$dataInventory->user->user_id;

        $slotNumber = CraftInventory::find()
            ->andWhere(['user_id' => $userId])
            ->max('slot');

        $slot = new CraftInventory([
            'item_id' => $item->id,
            'user_id' => $userId,
            'slot' => ++$slotNumber,
        ]);
        $slot->save();

        self::$dataInventory->addInventorySlots($slot);

        return parent::check();
    }

}
