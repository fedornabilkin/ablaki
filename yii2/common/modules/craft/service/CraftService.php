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
        (new Crafting(new CraftStorage(Yii::$app->db)))->command((int)$person->user_id,bin2hex(random_bytes(16)),'craft',['id'=>(int)$recipe->id,'quantity'=>1]);
    }
}
