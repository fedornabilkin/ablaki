<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 20:34
 */

namespace common\exceptions\person;

use Throwable;
use yii\web\HttpException;

class BalanceException extends HttpException
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        parent::__construct(400, 'Insufficient funds', 102, $previous);
    }
}
