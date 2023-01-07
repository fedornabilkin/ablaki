<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 20:32
 */

namespace common\middleware\person;

use common\exceptions\person\BalanceException;
use common\middleware\AbstractMiddleware;

class CheckBalanceMiddleware extends AbstractMiddleware
{
    public function check(): bool
    {
        if (self::$data->user->balance < self::$data->getNeedBalance()) {
            throw new BalanceException();
        }

        return parent::check();
    }
}
