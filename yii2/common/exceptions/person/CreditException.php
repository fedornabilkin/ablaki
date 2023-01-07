<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:10
 */

namespace common\exceptions\person;

use Throwable;
use yii\base\UserException;

class CreditException extends UserException
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct('Insufficient funds', 101, $previous);
    }
}
