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

class PlayMiddleware extends AbstractMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
//        var_dump('play');exit();
        $this->model = self::$data->model;

        $this->model->user_buyer = self::$data->user->user_id;
//        $this->model->created_at = time();
        $this->model->save();

        self::$data->historyType = $this->model->getHistoryType();
        self::$data->commissionAmount = 0.05;

        $this->updateData();

        return parent::check();
    }

    public function updateData()
    {
//            self::$data->changingBalance = $this->model->kon - self::$data->commissionAmount;
        self::$data->historyComment = 'Confirm exchange position #' . $this->model->id;
//            self::$data->changingRating = $this->model->normalizeRating(self::$data->user->rating);

        $next = new UpdatePersonMiddleware();
        $next->insertNext(new HistoryCommissionMiddleware());
        $this->insertNext($next);

    }
}
