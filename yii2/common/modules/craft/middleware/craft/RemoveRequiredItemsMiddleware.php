<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:22
 */

namespace common\modules\craft\middleware\craft;

use common\modules\craft\service\InventoryService;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class RemoveRequiredItemsMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        $requiredItems = self::$dataCraft->getRequiredItems();

        $inventory = new InventoryService();
        $person = self::$dataCraft->getPerson();

        foreach ($requiredItems as $item) {
            $inventory->removeItem($person, $item);
        }

        return parent::check();
    }

}
