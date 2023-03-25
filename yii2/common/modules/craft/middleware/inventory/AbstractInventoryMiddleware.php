<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 23:17
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractMiddleware;

class AbstractInventoryMiddleware extends AbstractMiddleware
{
    /** @var InventoryDataMiddleware */
    public static $dataInventory;
}
