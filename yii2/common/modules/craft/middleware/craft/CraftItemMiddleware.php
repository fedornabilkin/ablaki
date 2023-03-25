<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:22
 */

namespace common\modules\craft\middleware\craft;

/**
 * @property CraftDataMiddleware $dataCraft
 */
class CraftItemMiddleware extends AbstractCraftMiddleware
{
    public function check(): bool
    {
        self::$dataCraft->setResultItem(self::$dataCraft->getRecipe()->item);

        return parent::check();
    }

}
