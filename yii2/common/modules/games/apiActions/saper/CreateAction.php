<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 16:41
 */

namespace common\modules\games\apiActions\saper;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractCreate;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\middleware\GamerCheckBalanceMiddleware;
use common\modules\games\middleware\saper\CreateMiddleware;

class CreateAction extends AbstractCreate
{
    /**
     * @return array|bool
     * @throws \yii\base\UserException
     */
    public function run()
    {
        if (!$this->loadModel()) {
            return $this->model->getErrors();
        }

        $this->checkMiddleware();

        return true;
    }

    public function getMiddleware(): GameMiddleware
    {
        $middleware = new GamerCheckBalanceMiddleware();
        $middleware::$data = $this->getDataMiddleware();

        $middleware
            ->linkWith(new CreateMiddleware())
            ->linkWith(new UpdatePersonMiddleware());

        return $middleware;
    }
}
