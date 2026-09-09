<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:11
 */

namespace common\modules\craft\middleware\craft;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class RequiredItemsMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        $this->prepareItems();

        return parent::check();
    }

    public function prepareItems(): void
    {
        $recipe = self::$dataCraft->getRecipe();
        $items = $recipe->itemsSource;

        $itemsQuantity = $recipe->recipeItemsIndexItemId;

        foreach ($items as $item) {
            $item->setQuantity($itemsQuantity[$item->id]->item_quantity);
            self::$dataCraft->setRequiredItem($item);
        }
    }
}
