<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 22:22
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use common\middleware\HistoryCommissionMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\models\CreditExchange;

class PlayMiddleware extends AbstractMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        /** @var CreditExchange model */
        $this->model = self::$data->getModel();

        $this->model->user_buyer = self::$data->user->user_id;
        $this->model->save();

        self::$data->historyType = $this->model->getHistoryType();
        self::$data->commissionAmount = 0.05;
        self::$data->historyComment = 'Confirm exchange position #' . $this->model->id;

        $this->updateData();

        return parent::check();
    }

    public function updateData(): void
    {
//        self::$data->changingBalance = $this->model->kon - self::$data->commissionAmount;
//        self::$data->changingRating = $this->model->normalizeRating(self::$data->user->rating);

        $next = new UpdatePersonMiddleware();
        $next->insertNext(new HistoryCommissionMiddleware());
        $this->insertNext($next);

    }
}
