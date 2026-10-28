<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.03.2023
 * Time: 01:47
 */

namespace common\modules\world\modules\craft\models\domain;

use common\models\user\Person;
use common\modules\world\modules\craft\models\CraftRecipe;

class RecipeService
{
    public function hasRecipe(Person $person, CraftRecipe $recipe): bool
    {
        return (int)$recipe->active===1 && !(new Crafting(new CraftStorage(\Yii::$app->db)))->requirements((int)$person->user_id,$recipe->attributes);
    }
}
