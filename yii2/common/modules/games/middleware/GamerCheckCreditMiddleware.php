<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 14:28
 */

namespace common\modules\games\middleware;

/**
 * Class GamerCheckCreditMiddleware
 * @package common\modules\games\middleware
 */
class GamerCheckCreditMiddleware extends GameMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        if (!$this->checkCash()) {
            return $this->stopProcessing('Insufficient funds');
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkCash(): bool
    {
        return self::$data->user->credit >= (self::$data->game->kon * self::$data->game->count);
    }
}
