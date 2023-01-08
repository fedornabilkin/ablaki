<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 23:05
 */

namespace common\modules\exchange\middleware\exchange;

use common\middleware\AbstractMiddleware;
use common\middleware\HistoryCommissionMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\models\CreditExchange;
use yii\db\Exception;

class SwitchCreatorMiddleware extends AbstractMiddleware
{
    /**
     * @var CreditExchange
     */
    private $model;

    /**
     * @return bool
     * @throws Exception
     */
    public function check(): bool
    {
        $this->model = self::$data->model;

        self::$data->setUser($this->model->user->person); // Далее работаем с персоной создателя игры

        self::$data->historyComment = 'Satisfy #' . $this->model->id;

        self::$data->changingCredit = 0;
        self::$data->changingBalance = 0;

        if ($this->model->isSell()) {
//            self::$data->historyType .= '_cr';
            self::$data->commissionAmount = $this->model->credit * 0.05;
            self::$data->changingCredit = $this->model->credit - self::$data->commissionAmount;
        }

        if ($this->model->isBuy()) {
//            self::$data->historyType .= '_kg';
            self::$data->commissionAmount = $this->model->amount * 0.05;
            self::$data->changingBalance = $this->model->amount - self::$data->commissionAmount;
        }

        $next = new UpdatePersonMiddleware();
        $next->insertNext(new HistoryCommissionMiddleware());
        $this->insertNext($next);

        return parent::check();
    }
}
