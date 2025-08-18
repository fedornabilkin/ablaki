<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 20:34
 */

namespace common\exceptions\person;

use Throwable;
use yii\web\BadRequestHttpException;

class BalanceException extends BadRequestHttpException
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        parent::__construct('Insufficient funds', 102, $previous);
    }
}
