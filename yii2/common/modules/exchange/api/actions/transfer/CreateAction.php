<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.01.2022
 * Time: 22:42
 */

namespace common\modules\exchange\api\actions\transfer;

use common\helpers\App;
use common\modules\exchange\models\CreditTransfer;
use common\modules\exchange\service\TransferService;
use Yii;
use yii\base\Model;
use yii\rest\Action;

class CreateAction extends Action
{
    public $scenario = Model::SCENARIO_DEFAULT;

    public function run()
    {
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id);
        }

        /* @var $model CreditTransfer */
        $model = new $this->modelClass();
        $model->scenario = $this->scenario;

        $model->load(Yii::$app->getRequest()->getBodyParams(), '');

        $service = App::container()->get(TransferService::class);
        $service->create($model);

        App::response()->setStatusCode(201);
    }
}
