<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 13:57
 */

namespace common\modules\exchange\api\controllers;

use common\helpers\App;
use common\modules\exchange\api\actions\transfer\{CreateAction, DeleteAction, UpdateAction};
use common\modules\exchange\api\models\CreditTransfer;
use Yii;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\ForbiddenHttpException;

class TransferController extends ActiveController
{
    public $modelClass = CreditTransfer::class;

    public function actions()
    {
        $actions = parent::actions();

        $actions['create']['class'] = CreateAction::class;
        $actions['update']['class'] = UpdateAction::class;
        $actions['delete']['class'] = DeleteAction::class;
        $actions['history'] = $actions['index'];

        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->orderBy(['created_at' => SORT_DESC])
                    ->with(['user'])
                    ->my(App::user()->identity)
                    ->free()
            ]);
        };

        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->orderBy(['created_at' => SORT_DESC])
                    ->with(['user'])
                    ->listHistory(App::user()->identity)
            ]);
        };

        return $actions;
    }

    public function checkAccess($action, $model = null, $params = []): void
    {
        parent::checkAccess($action, $model, $params);

        if (
            ($action === 'delete' && $model->user_id !== App::user()->id)
            || ($action === 'view' && $model->user_id !== App::user()->id)
            || ($action === 'update' && $model->user_id === App::user()->id)
        ) {
            throw new ForbiddenHttpException(
                Yii::t('exchange', sprintf('The %s action is not available.', $action)),
            );
        }
    }
}
