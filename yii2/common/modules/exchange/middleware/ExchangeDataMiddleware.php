<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.01.2022
 * Time: 23:06
 */

namespace common\modules\exchange\middleware;

use common\middleware\DataMiddleware;
use common\modules\exchange\api\models\CreditExchange;

/**
 *
 */
class ExchangeDataMiddleware extends DataMiddleware
{
    /**
     * @var CreditExchange
     */
    protected $model;

    public function __construct($user, CreditExchange $model, array $config = [])
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
        return (int)$this->model->credit * $this->model->count;
    }

    /**
     * @return int
     */
    public function getNeedBalance(): int
    {
        return (int)$this->model->amount * $this->model->count;
    }
}
