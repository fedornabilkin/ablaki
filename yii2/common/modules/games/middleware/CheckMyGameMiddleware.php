<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 17:54
 */

namespace common\modules\games\middleware;

use yii\db\Exception;

class CheckMyGameMiddleware extends GameMiddleware
{
    /**
     * @return bool
     * @throws Exception
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
