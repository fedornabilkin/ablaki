<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:37
 */

namespace common\modules\exchange\middleware\exchange;

use common\middleware\AbstractMiddleware;
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\exception\CountException;
use yii\db\Exception;

class CheckCountMiddleware extends AbstractMiddleware
{
    /** @var CreditExchange */
    protected $model;

    /**
     * @return bool
     * @throws CountException
     * @throws Exception
     */
    public function check(): bool
    {
        $this->model = self::$data->getModel();

        if ($this->model->isBuy() && self::$data->getAvailableCount() < $this->model->count) {
            $this->model->count = self::$data->getAvailableCount();
            if ($this->model->count < 1) {
                throw new CountException();
            }
        }

        return parent::check();
    }
}
