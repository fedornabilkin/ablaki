<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.04.2022
 * Time: 22:47
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use common\models\user\Person;
use common\modules\exchange\api\models\CreditExchange;

class RemoveAllMiddleware extends AbstractMiddleware
{
    protected $where;
    protected $credit = 0;
    protected $balance = 0;

    protected $querySell;
    protected $queryBuy;

    /**
     * @var Person
     */
    private $user;
    /**
     * @var CreditExchange
     */
    private $model;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->user = self::$data->getUser();
        $this->model = self::$data->getModel();

        $this->updateData();
        if ($this->balance > 0 || $this->credit > 0) {
            $this->remove();
        }

        return parent::check();
    }

    public function updateData(): void
    {
        // query sell
        $this->querySell = $this->model::find()->onlySell()->free()->my($this->user->user);
        $this->balance = $this->querySell->sum($this->model::balanceFieldName());

        // query buy
        $this->queryBuy = $this->model::find()->onlyBuy()->free()->my($this->user->user);
        $this->credit = $this->querySell->sum($this->model::creditFieldName());


        if ($this->credit > 0 || $this->balance > 0) {
            self::$data->historyType = $this->model->getHistoryType();
            self::$data->historyComment = 'Remove all';
        }

        if ($this->credit > 0) {
            self::$data->changingCredit = $this->credit;
        }

        if ($this->balance > 0) {
            self::$data->changingBalance = $this->balance;
        }
    }

    public function remove(): void
    {
        $arr = array_merge($this->querySell->column(), $this->queryBuy->column());
        if (isset($arr[0])) {
            $this->model::deleteAll(['in', 'id', $arr]);
        }
    }
}
