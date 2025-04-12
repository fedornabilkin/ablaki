<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 20:34
 */

namespace common\modules\games\exception;

use Throwable;
use yii\web\HttpException;

class MainException extends HttpException
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        $message = $message ?: '';
        parent::__construct(400, $message, 401, $previous);
    }
}
