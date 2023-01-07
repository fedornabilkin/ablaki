<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.12.2021
 * Time: 0:26
 */

namespace common\modules\games\middleware;

class CheckNotMyGameMiddleware extends GameMiddleware
{
    public function check(): bool
    {
        if (!$this->checkGame()) {
            return $this->stopProcessing('Is my game');
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkGame(): bool
    {
        return self::$data->game->user_id !== self::$data->user->user_id;
    }
}
