<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 21:27
 */

namespace common\modules\games\apiActions\saper;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\AbstractRemove;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\middleware\saper\RemoveAllGameMiddleware;

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
