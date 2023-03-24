<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 20:32
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractDataMiddleware;
use common\modules\exchange\models\CreditTransfer;

class TransferDataMiddleware extends AbstractDataMiddleware
{
    /**
     * @var CreditTransfer
     */
    protected $model;

    public function __construct($user, CreditTransfer $model, array $config = [])
    {
        parent::__construct($config);
        $this->model = $model;
        $this->user = $user;
    }

    /**
     * @return int
     */
    public function getNeedCredit(): int
    {
        return (int)$this->model->amount * $this->model->count;
    }
}
