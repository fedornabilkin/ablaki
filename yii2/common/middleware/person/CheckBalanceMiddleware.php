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
use yii\db\Exception;

class CheckBalanceMiddleware extends AbstractMiddleware
{
    /**
     * @return bool
     * @throws BalanceException
     * @throws Exception
     */
    public function check(): bool
    {
        if (self::$data->user->balance < self::$data->getNeedBalance()) {
            throw new BalanceException();
        }

        return parent::check();
    }
}
