<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 22:33
 */

namespace common\modules\exchange\api\actions\exchange;

use common\helpers\App;
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\service\ExchangeService;
use yii\rest\Action;

class DeleteAction extends Action
{
    public function run($id)
    {
        /* @var $model CreditExchange */
        $model = $this->findModel($id);

        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }

        $service = App::container()->get(ExchangeService::class);
        $service->delete($model);

        return $model;
    }
}
