<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 22:04
 */

namespace common\modules\exchange\api\actions\transfer;

use common\helpers\App;
use common\modules\exchange\models\CreditTransfer;
use common\modules\exchange\service\TransferService;
use yii\base\Model;
use yii\rest\Action;

class UpdateAction extends Action
{
    public $scenario = Model::SCENARIO_DEFAULT;

    public function run($id)
    {
        /* @var $model CreditTransfer */
        $model = $this->findModel($id);

        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }

        $service = App::container()->get(TransferService::class);
        $service->confirm($model);

        return $model;
    }
}
