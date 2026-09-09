<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:23
 */

namespace common\modules\craft\middleware\craft;

use common\modules\craft\models\CraftHistory;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class CraftingHistoryMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        $history = new CraftHistory([
            'user_id' => self::$dataCraft->getPerson()->user_id,
            'item_id' => self::$dataCraft->getResultItem()->id,
            'recipe_id' => self::$dataCraft->getRecipe()->id,
        ]);

        $history->save();

        return parent::check();
    }

}
