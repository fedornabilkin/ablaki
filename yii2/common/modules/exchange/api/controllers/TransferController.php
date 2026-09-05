<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 13:57
 */

namespace common\modules\exchange\api\controllers;

use api\components\ApiList;
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
            return $this->listProvider($this->modelClass::find()
                ->with(['user', 'userBuyer'])->my(App::user()->identity)->free());
        };

        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            return $this->listProvider($this->modelClass::find()
                ->with(['user', 'userBuyer'])->listHistory(App::user()->identity));
        };

        return $actions;
    }

    private function listProvider($query): ActiveDataProvider
    {
        return ApiList::provider($query, [], ['id', 'created_at', 'amount'], static function ($query, string $q): void {
            $where = ApiList::relatedUserCondition(['user_id', 'user_buyer'], $q);
            if (ctype_digit($q)) {
                $where[] = ['id' => (int)$q];
            }
            if (is_numeric($q)) {
                $where[] = ['amount' => (float)$q];
            }
            $query->andWhere($where);
        });
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
