<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 22:32
 */

namespace common\modules\exchange\api\actions\exchange;

use common\helpers\App;
use common\modules\exchange\service\ExchangeService;
use yii\rest\Action;

class RemoveAction extends Action
{
    public function run()
    {
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id);
        }

        $service = App::container()->get(ExchangeService::class);
        $service->remove();

        App::response()->setStatusCode(204);
    }
}
