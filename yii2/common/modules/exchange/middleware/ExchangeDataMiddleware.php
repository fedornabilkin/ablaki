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

    private $availableCount;

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
        return (int)($this->model->credit * $this->model->count);
    }

    /**
     * @return float
     */
    public function getNeedBalance(): float
    {
        return (float)($this->model->amount * $this->model->count);
    }

    /**
     * @return mixed
     */
    public function getAvailableCount()
    {
        return $this->availableCount;
    }

    /**
     * @param mixed $availableCount
     */
    public function setAvailableCount($availableCount): void
    {
        $this->availableCount = $availableCount;
    }
}
