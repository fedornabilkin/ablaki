<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 0:57
 */

namespace common\modules\games\middleware\saper;

use common\middleware\AbstractMiddleware;
use common\modules\games\middleware\GameDataMiddleware;
use common\modules\games\models\GameSaper;

class StartMiddleware extends AbstractMiddleware
{
    /** @var GameSaper */
    private $model;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->model = self::$data->game;

        $this->updateData();
        if (!$this->start()) {
            return $this->stopProcessing('Error start game');
        }

        return parent::check();
    }

    public function updateData()
    {
        self::$data->changingBalance = 0 - self::$data->getKon() * self::$data->getCount();
        self::$data->historyType = $this->model->getHistoryType();
        self::$data->historyComment = 'Start game #' . $this->model->id;
    }

    public function start()
    {
        $this->model->user_gamer = self::$data->user->user_id;
        $this->model->time_start_at = time();

        return $this->model->save();
    }
}
