<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 18:24
 */

namespace common\modules\games\apiActions\orel;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractDelete;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\CheckMyGameMiddleware;
use common\modules\games\middleware\orel\RemoveGameMiddleware;
use yii\base\UserException;

class DeleteAction extends AbstractDelete
{
    /**
     * @param $id
     * @throws UserException
     */
    public function run($id)
    {
        $this->loadModel($id);
        $this->checkMiddleware();
    }

    public function getMiddleware(): GameMiddleware
    {
        $middleware = new CheckFreeGameMiddleware();
        $middleware::$data = $this->getDataMiddleware();

        $middleware
            ->linkWith(new CheckMyGameMiddleware())
            ->linkWith(new RemoveGameMiddleware())
            ->linkWith(new UpdatePersonMiddleware());

        return $middleware;
    }
}
