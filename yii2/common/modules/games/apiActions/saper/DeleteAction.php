<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 16:58
 */

namespace common\modules\games\apiActions\saper;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractDelete;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\CheckMyGameMiddleware;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\middleware\saper\RemoveGameMiddleware;

class DeleteAction extends AbstractDelete
{
    /**
     * @param $id
     * @throws \yii\base\UserException
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
