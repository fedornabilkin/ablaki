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

        $totals=[];
        foreach($availableItems as $availableItem)$totals[$availableItem->item_id]=($totals[$availableItem->item_id]??0)+$availableItem->getQuantity();
        foreach($requiredItems as $itemId=>$item)if(($totals[$itemId]??0)<$item->getQuantity())throw new InsufficientResourcesException();

        return parent::check();
    }

}
