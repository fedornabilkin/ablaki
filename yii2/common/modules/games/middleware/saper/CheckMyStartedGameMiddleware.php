<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 15:19
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\GameMiddleware;

class CheckMyStartedGameMiddleware extends GameMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        if (!$this->checkGame()) {
            return $this->stopProcessing('No my starting game');
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkGame(): bool
    {
        return self::$data->game->user_gamer === self::$data->user->user_id;
    }
}
