<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 15:26
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractMiddleware;
use common\modules\craft\exception\FreeSlotException;

class CheckFreeSlotMiddleware extends AbstractMiddleware
{

    public function check(): bool
    {
        if (1 !== 1) {
            throw new FreeSlotException();
        }

        return parent::check();
    }
}
