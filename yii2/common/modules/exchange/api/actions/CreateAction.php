<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.01.2022
 * Time: 22:42
 */

namespace common\modules\exchange\api\actions;

use common\helpers\App;
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\api\requests\CreateRequest;
use common\modules\exchange\service\ExchangeService;
use Yii;
use yii\rest\Action;

class CreateAction extends Action
{
    public function run()
    {
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id);
        }

        /* @var $model CreditExchange */
        $model = new $this->modelClass([
//            'scenario' => $this->scenario,
        ]);

        $model->load(Yii::$app->getRequest()->getBodyParams(), '');

        $request = new CreateRequest($model->amount, $model->credit, $model->type, $model->count);
        (new ExchangeService())->create($request);
        App::response()->setStatusCode(201);
    }
}
