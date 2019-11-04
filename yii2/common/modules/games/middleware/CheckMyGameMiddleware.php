<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 17:54
 */

namespace common\modules\games\middleware;

class CheckMyGameMiddleware extends GameMiddleware
{
    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function check(): bool
    {
        if (!$this->checkGame()) {
            return $this->stopProcessing('No my game');
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkGame(): bool
    {
        return self::$data->game->user_id === self::$data->user->user_id;
    }
}
