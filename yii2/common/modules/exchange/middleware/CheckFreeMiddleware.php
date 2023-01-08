<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 23:37
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use common\modules\exchange\exception\FreeException;
use yii\db\Exception;

class CheckFreeMiddleware extends AbstractMiddleware
{
    /**
     * @return bool
     * @throws FreeException
     * @throws Exception
     */
    public function check(): bool
    {
        if (!$this->checkPosition()) {
            throw new FreeException();
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
