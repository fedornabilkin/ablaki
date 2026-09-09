<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 15:21
 */

namespace common\modules\craft\service;

use common\models\user\Person;
use common\modules\craft\middleware\craft\AddCraftedItemMiddleware;
use common\modules\craft\middleware\craft\CheckRecipePersoneMiddleware;
use common\modules\craft\middleware\craft\CheckRequiredItemsMiddleware;
use common\modules\craft\middleware\craft\CraftDataMiddleware;
use common\modules\craft\middleware\craft\CraftingHistoryMiddleware;
use common\modules\craft\middleware\craft\CraftItemMiddleware;
use common\modules\craft\middleware\craft\RemoveRequiredItemsMiddleware;
use common\modules\craft\middleware\craft\RequiredItemsMiddleware;
use common\modules\craft\models\CraftRecipe;
use Exception;
use Yii;

class CraftService
{
    public function craftItem(Person $person, CraftRecipe $recipe): void
    {
        $middleware = new CheckRecipePersoneMiddleware();
        $middleware
            ->linkWith(new RequiredItemsMiddleware())
            ->linkWith(new CheckRequiredItemsMiddleware())
            ->linkWith(new CraftItemMiddleware())
            ->linkWith(new RemoveRequiredItemsMiddleware())
            ->linkWith(new AddCraftedItemMiddleware())
            ->linkWith(new CraftingHistoryMiddleware());

        $data = new CraftDataMiddleware();
        $data->setPerson($person);
        $data->setRecipe($recipe);

        $middleware::$dataCraft = $data;

        if (!$middleware->check()) {
            throw new Exception(Yii::t('craft', 'Error craft item'));
        }
    }
}
