<?php

namespace common\modules\games\apiActions\duel;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractDelete;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\CheckMyGameMiddleware;
use common\modules\games\middleware\duel\RemoveGameMiddleware;
use common\modules\games\middleware\GameMiddleware;
use yii\base\UserException;

class DeleteAction extends AbstractDelete
{
    /**
     * @param $id
     * @throws UserException
     */
    public function run($id): void
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
