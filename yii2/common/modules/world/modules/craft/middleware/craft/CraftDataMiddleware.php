<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:27
 */

namespace common\modules\world\modules\craft\middleware\craft;

use common\middleware\AbstractDataMiddleware;
use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\CraftRecipe;

class CraftDataMiddleware extends AbstractDataMiddleware
{
    /** @var CraftRecipe */
    protected $recipe;

    /** @var CraftItem[] */
    protected $requiredItems = [];

    /** @var CraftItem */
    protected $resultItem;

    /**
     * @return CraftRecipe
     */
    public function getRecipe(): CraftRecipe
    {
        return $this->recipe;
    }

    /**
     * @param CraftRecipe $recipe
     */
    public function setRecipe(CraftRecipe $recipe): void
    {
        $this->recipe = $recipe;
    }

    /**
     * @return CraftItem[]
     */
    public function getRequiredItems(): array
    {
        return $this->requiredItems;
    }

    /**
     * @param CraftItem $requiredItem
     */
    public function setRequiredItem(CraftItem $requiredItem): void
    {
        $this->requiredItems[$requiredItem->id] = $requiredItem;
    }

    /**
     * @return CraftItem
     */
    public function getResultItem(): CraftItem
    {
        return $this->resultItem;
    }

    /**
     * @param CraftItem $resultItem
     */
    public function setResultItem(CraftItem $resultItem): void
    {
        $this->resultItem = $resultItem;
    }
}
