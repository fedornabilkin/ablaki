<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 15:47
 */

namespace common\modules\craft\exception;

use Exception;
use Throwable;

class FreeSlotException extends Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, 501, $previous);
    }
}
