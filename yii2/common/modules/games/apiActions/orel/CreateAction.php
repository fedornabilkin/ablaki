<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 22:16
 */

namespace common\modules\games\apiActions\orel;

use common\middleware\AbstractMiddleware;
use common\middleware\person\CheckCreditMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractCreate;
use common\modules\games\middleware\orel\CreateMiddleware;
use yii\base\UserException;

class CreateAction extends AbstractCreate
{
    /**
     * @return array|bool
     * @throws UserException
     */
    public function run()
    {
        if (!$this->loadModel()) {
            return $this->model->getErrors();
        }

        $this->checkMiddleware();

        return true;
    }

    public function getMiddleware(): AbstractMiddleware
    {
        $middleware = new CheckCreditMiddleware();
        $middleware::$data = $this->getDataMiddleware();

        $middleware
            ->linkWith(new CreateMiddleware())
            ->linkWith(new UpdatePersonMiddleware());

        return $middleware;
    }
}
