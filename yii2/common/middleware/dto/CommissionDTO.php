<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.12.2021
 * Time: 22:26
 */

namespace common\middleware\dto;

class CommissionDTO
{
    /**
     * @var float
     */
    public $amount;
    /**
     * @var string
     */
    public $type;

    public function __construct(float $amount, string $type)
    {
        $this->amount = $amount;
        $this->type = $type;
    }
}
