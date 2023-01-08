<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 22:50
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractMiddleware;
use common\models\user\Person;
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\exception\MyException;

class CheckMyMiddleware extends AbstractMiddleware
{
    /**
     * @var CreditExchange
     */
    private $model;
    /**
     * @var Person
     */
    private $user;

    /**
     * @return bool
     */
    public function check(): bool
    {
        $this->model = self::$data->getModel();
        $this->user = self::$data->getUser();

        if (!$this->checkMy()) {
            throw new MyException();
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function checkMy(): bool
    {
        return $this->model->user_id === $this->user->user_id;
    }
}
