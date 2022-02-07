<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:37
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use yii\base\UserException;

class CheckCountMiddleware extends AbstractMiddleware
{
    public function check(): bool
    {
        if (0 < 1) {
            throw new UserException();
        }

        return parent::check();
    }
}
