<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 0:57
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\GameMiddleware;

class StartMiddleware extends GameMiddleware
{

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->updateData();
        if (!$this->start()) {
            return $this->stopProcessing('Error start game');
        }

        return parent::check();
    }

    public function updateData()
    {
        self::$data->changingBalance = 0 - self::$data->game->kon * self::$data->game->count;
        self::$data->historyType = self::$data->game->getHistoryType();
        self::$data->historyComment = 'Start game #' . self::$data->game->id;
    }

    public function start()
    {
        self::$data->game->user_gamer = self::$data->user->user_id;
        self::$data->game->time_start_at = time();

        return self::$data->game->save();
    }
}
