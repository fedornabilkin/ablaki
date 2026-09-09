<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 25.03.2023
 * Time: 22:18
 */

namespace common\exceptions\person;

use Exception;
use Throwable;

class RatingException extends Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct('Insufficient rating', 103, $previous);
    }
}
