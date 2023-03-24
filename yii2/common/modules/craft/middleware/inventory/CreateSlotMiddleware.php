<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.03.2023
 * Time: 19:39
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractMiddleware;
use common\modules\craft\models\CraftInventory;

/**
 * @property InventoryDataMiddleware $data
 */
class CreateSlotMiddleware extends AbstractMiddleware
{
    public function check(): bool
    {
        $item = self::$data->getItem();
        $userId = self::$data->user->user_id;

        $slotNumber = CraftInventory::find()
            ->andWhere(['user_id' => $userId])
            ->max('slot');

        $slot = new CraftInventory([
            'item_id' => $item->id,
            'user_id' => $userId,
            'slot' => ++$slotNumber,
        ]);
        $slot->save();

        self::$data->addInventorySlots($slot);

        return parent::check();
    }

}
