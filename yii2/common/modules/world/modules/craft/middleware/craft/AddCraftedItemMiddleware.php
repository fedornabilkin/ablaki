<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:23
 */

namespace common\modules\world\modules\craft\middleware\craft;

use common\modules\world\modules\craft\models\domain\InventoryService;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class AddCraftedItemMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        $inventory = new InventoryService();
        $item = self::$dataCraft->getResultItem();
        $person = self::$dataCraft->getPerson();

        $inventory->addItem($person, $item);

        return parent::check();
    }

}
