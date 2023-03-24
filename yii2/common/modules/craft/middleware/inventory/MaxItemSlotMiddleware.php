<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 16:42
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractMiddleware;
use common\modules\craft\exception\MaxItemSlotException;

class MaxItemSlotMiddleware extends AbstractMiddleware
{
    public function check(): bool
    {
        if (1 !== 1) {
            throw new MaxItemSlotException();
        }

        return parent::check();
    }

}
