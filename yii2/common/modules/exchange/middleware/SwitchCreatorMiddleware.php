<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 23:05
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use yii\db\Exception;

class SwitchCreatorMiddleware extends AbstractMiddleware
{
    /**
     * @return bool
     * @throws Exception
     */
    public function check(): bool
    {
        $this->model = self::$data->model;

        self::$data->user = $this->model->user->person; // Далее работаем с персоной создателя игры

//        self::$data->changingBalance = $this->model->kon * 2 - self::$data->commissionAmount;
        self::$data->historyComment = 'Confirm exchange position #' . $this->model->id;
//        self::$data->changingRating = $this->model->normalizeRating(self::$data->user->rating);

        $this->insertNext(new UpdatePersonMiddleware());

        return parent::check();
    }
}
