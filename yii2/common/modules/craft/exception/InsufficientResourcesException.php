<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 20:43
 */

namespace common\modules\craft\exception;

use Exception;
use Throwable;
use Yii;

class InsufficientResourcesException extends Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct(Yii::t('craft', 'Insufficient resources'), 503, $previous);
    }
}
