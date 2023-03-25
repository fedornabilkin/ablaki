<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 23:09
 */

namespace common\modules\craft\middleware\craft;

use common\middleware\AbstractMiddleware;

class AbstractCraftMiddleware extends AbstractMiddleware
{
    /** @var CraftDataMiddleware */
    public static $dataCraft;
}
