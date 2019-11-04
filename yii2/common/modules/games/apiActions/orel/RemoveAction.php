<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 21:06
 */

namespace common\modules\games\apiActions\orel;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractRemove;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\middleware\orel\RemoveAllGameMiddleware;

class RemoveAction extends AbstractRemove
{
    public function run()
    {
        $this->checkMiddleware();
    }

    public function getMiddleware(): GameMiddleware
    {
        $middleware = new RemoveAllGameMiddleware();
        $middleware::$data = $this->getDataMiddleware();

        $middleware
            ->linkWith(new UpdatePersonMiddleware());

        return $middleware;
    }
}
