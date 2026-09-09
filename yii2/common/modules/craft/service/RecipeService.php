<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.03.2023
 * Time: 01:47
 */

namespace common\modules\craft\service;

use common\models\user\Person;
use common\modules\craft\models\CraftRecipe;

class RecipeService
{
    public function hasRecipe(Person $person, CraftRecipe $recipe): bool
    {
        return true;
    }
}
