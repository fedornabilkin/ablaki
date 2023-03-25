<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.03.2023
 * Time: 01:45
 */

namespace common\modules\craft\middleware\craft;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class CheckRecipePersoneMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        $recipe = self::$dataCraft->getRecipe();
        $person = self::$dataCraft->getPerson();


        return parent::check();
    }

}
