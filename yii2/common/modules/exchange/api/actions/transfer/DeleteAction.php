<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 22:33
 */

namespace common\modules\exchange\api\actions\transfer;

use common\helpers\App;
use common\modules\exchange\models\CreditTransfer;
use common\modules\exchange\service\TransferService;
use yii\rest\Action;

class DeleteAction extends Action
{
    public function run($id)
    {
        /* @var $model CreditTransfer */
        $model = $this->findModel($id);

        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }

        $service = App::container()->get(TransferService::class);
        $service->delete($model);

        return $model;
    }
}
