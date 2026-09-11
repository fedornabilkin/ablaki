<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.02.2023
 * Time: 22:17
 */

namespace common\modules\forum\api\controllers;

use api\modules\v1\models\forum\Theme;
use api\components\ApiList;
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

        $actions['index']['prepareDataProvider'] = function () {
            return ApiList::provider($this->modelClass::find(), ['title'], ['id', 'user_id', 'created_at', 'last_post', 'title', 'last_comment_text', 'last_comment_username', 'last_comment_created_at', 'first_comment_user_id', 'first_comment_username']);
        };
        $actions['my'] = $actions['index'];
        $actions['my']['prepareDataProvider'] = function () {
            return ApiList::provider($this->modelClass::find()->my(App::user()->identity), ['title'], ['id', 'user_id', 'created_at', 'last_post', 'title', 'last_comment_text', 'last_comment_username', 'last_comment_created_at', 'first_comment_user_id', 'first_comment_username']);
        };
        $actions['create']['scenario'] = 'create';
        $actions['update']['scenario'] = 'update';

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
