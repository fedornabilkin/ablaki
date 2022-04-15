<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 22:59
 */

namespace common\modules\exchange\exception;

use Throwable;
use yii\base\UserException;

class MyException extends UserException
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct('Not my position', 202, $previous);
    }
}
