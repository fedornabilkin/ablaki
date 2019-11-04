<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 13:48
 */

namespace common\modules\games\middleware;

class CheckFreeGameMiddleware extends GameMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        if (!$this->checkGame()) {
            return $this->stopProcessing('No free game');
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkGame(): bool
    {
        return !self::$data->game->user_gamer || self::$data->game->user_gamer < 1;
    }
}
