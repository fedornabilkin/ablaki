<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.01.2022
 * Time: 22:58
 */

namespace common\middleware\person;

use common\exceptions\person\CreditException;
use common\middleware\AbstractMiddleware;

class CheckCreditMiddleware extends AbstractMiddleware
{
    public function check(): bool
    {
        if (self::$data->user->credit < self::$data->getNeedCredit()) {
            throw new CreditException();
        }

        return parent::check();
    }
}
