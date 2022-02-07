<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.01.2022
 * Time: 22:48
 */

namespace common\modules\exchange\api\requests;

use common\middleware\dto\Request;

class CreateRequest extends Request
{
    /**
     * @var float
     */
    public $amount;
    /**
     * @var int
     */
    public $credit;
    /**
     * @var string
     */
    public $type;
    /**
     * @var int
     */
    public $count;

    public function __construct(float $amount, int $credit, string $type, int $count = 1)
    {
        $this->amount = $amount;
        $this->credit = $credit;
        $this->type = $type;
        $this->count = $count;
    }

}
