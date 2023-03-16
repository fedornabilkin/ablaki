<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.02.2023
 * Time: 22:17
 */

namespace common\modules\forum\api\controllers;

use api\modules\v1\models\forum\Theme;
use common\helpers\App;
use Yii;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\ForbiddenHttpException;

class ThemeController extends ActiveController
{
    public $modelClass = Theme::class;

    public function actions()
    {
        $actions = parent::actions();

        $actions['my'] = $actions['index'];
        $actions['my']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->my(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        unset($actions['delete']); // remove awards and history balance?
        return $actions;
    }

    public function checkAccess($action, $model = null, $params = []): void
    {
        parent::checkAccess($action, $model, $params);

        if (
            ($action === 'delete' && $model->user_id !== App::user()->id)
            || ($action === 'update' && $model->user_id !== App::user()->id)
        ) {
            throw new ForbiddenHttpException(
                Yii::t('forum', sprintf('The %s action is not available.', $action)),
            );
        }
    }
}
