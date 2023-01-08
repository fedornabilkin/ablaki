<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 21:44
 */

namespace common\modules\exchange\exception;

use Throwable;
use yii\base\UserException;

class FreeException extends UserException
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct('Not free position', 203, $previous);
    }
}
