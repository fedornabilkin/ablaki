<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 23:37
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;

class CheckFreeMiddleware extends AbstractMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        if (!$this->checkPosition()) {
            // todo FreeException
            return $this->stopProcessing('No free position');
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkPosition(): bool
    {
        return !self::$data->model->user_buyer || self::$data->model->user_buyer < 1;
    }
}
