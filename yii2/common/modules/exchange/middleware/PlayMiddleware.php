<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 22:22
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\models\CreditExchange;

class PlayMiddleware extends AbstractMiddleware
{
    /**
     * @var CreditExchange
     */
    private $model;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->model = self::$data->getModel();

        $this->model->user_buyer = self::$data->user->user_id;
        $this->model->save();

        self::$data->historyType = $this->model->getHistoryType();
        self::$data->historyComment = 'Confirm #' . $this->model->id;

        $this->updateData();

        return parent::check();
    }

    public function updateData(): void
    {
        if ($this->model->isSell()) {
            self::$data->changingBalance = $this->model->amount;
            self::$data->changingCredit = 0 - $this->model->credit;
        }

        if ($this->model->isBuy()) {
            self::$data->changingCredit = $this->model->credit;
            self::$data->changingBalance = 0 - $this->model->amount;
        }

        $this->insertNext(new UpdatePersonMiddleware());

    }
}
