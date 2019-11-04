<?php

namespace common\modules\games\middleware;

/**
 * Class GamerCheckBalanceMiddleware
 * @package common\modules\games\middleware
 */
class GamerCheckBalanceMiddleware extends GameMiddleware
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
        return self::$data->user->balance >= (self::$data->game->kon * self::$data->game->count);
    }
}
