<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:21
 */

namespace common\modules\craft\middleware\craft;

use common\modules\craft\exception\InsufficientResourcesException;
use common\modules\craft\service\InventoryService;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class CheckRequiredItemsMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        $inventory = new InventoryService();
        $requiredItems = self::$dataCraft->getRequiredItems();
        $person = self::$dataCraft->getPerson();
        $availableItems = $inventory->availableItems($person, ...array_keys($requiredItems));

        foreach ($availableItems as $availableItem) {
            if ($inventory->deficitItem($availableItem, $requiredItems[$availableItem->id])) {
                throw new InsufficientResourcesException();
            }
        }

        return parent::check();
    }

}
