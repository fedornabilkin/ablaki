<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 15:18
 */

namespace common\modules\craft\service;


use common\models\user\Person;
use common\modules\craft\middleware\inventory\ChangeQuantityMiddleware;
use common\modules\craft\middleware\inventory\CheckFreeSlotMiddleware;
use common\modules\craft\middleware\inventory\DispenseItemMiddleware;
use common\modules\craft\middleware\inventory\InventoryDataMiddleware;
use common\modules\craft\middleware\inventory\MaxItemSlotMiddleware;
use common\modules\craft\models\CraftItem;
use Exception;
use Yii;

class InventoryService
{
    public function addItem(Person $person, CraftItem $item): void
    {
        $middleware = new CheckFreeSlotMiddleware();
        $middleware->linkWith(new MaxItemSlotMiddleware())
            ->linkWith(new DispenseItemMiddleware())
            ->linkWith(new ChangeQuantityMiddleware());

        $data = new InventoryDataMiddleware($person);
        $data->setItem($item);
        $middleware::$data = $data;

        if (!$middleware->check()) {
            throw new Exception(Yii::t('craft', 'Error add item in inventory'));
        }
    }

    public function removeItem()
    {

    }

    public function moveItem()
    {

    }
}
