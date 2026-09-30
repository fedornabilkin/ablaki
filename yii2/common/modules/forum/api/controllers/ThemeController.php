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

    protected function verbs()
    {
        return array_merge(parent::verbs(), ['visit' => ['POST'], 'close' => ['PATCH'], 'delete' => ['DELETE']]);
    }

    public function actionVisit(int $id): array
    {
        if (!\common\modules\forum\models\ForumTheme::find()->where(['forum_theme.id' => $id])->exists()) throw new \yii\web\NotFoundHttpException();
        $changed = Yii::$app->db->createCommand()->update('forum_theme', [
            'view' => new \yii\db\Expression('COALESCE([[view]], 0) + 1'),
        ], ['id' => $id])->execute();
        if ($changed !== 1) throw new \yii\web\NotFoundHttpException();
        return ['view' => (int)(new \yii\db\Query())->select('view')->from('forum_theme')->where(['id' => $id])->scalar()];
    }

    public function actionClose(int $id): array
    {
        $theme = $this->modelClass::findOne($id);
        if (!$theme) throw new \yii\web\NotFoundHttpException();
        if ((int)$theme->user_id !== (int)App::user()->id && !Yii::$app->user->can('/forum/theme/update')) {
            throw new ForbiddenHttpException();
        }
        $closed = Yii::$app->request->getBodyParam('is_closed');
        if (!in_array($closed, [true, false, 0, 1, '0', '1'], true)) {
            throw new \yii\web\UnprocessableEntityHttpException('Укажите состояние темы.');
        }
        $theme->is_closed = (int)(bool)$closed;
        if (!$theme->save(false, ['is_closed'])) throw new \yii\web\ServerErrorHttpException('Не удалось изменить тему.');
        return ['id' => (int)$theme->id, 'is_closed' => (bool)$theme->is_closed];
    }

    public function actionDelete(int $id): array
    {
        if (!Yii::$app->user->can('/forum/theme/delete')) throw new ForbiddenHttpException();
        \common\modules\forum\services\ThemeDeleteService::delete(Yii::$app->db, $id);
        return ['deleted' => true];
    }

    public function actions()
    {
        $actions = parent::actions();

        $actions['index']['prepareDataProvider'] = function () {
            $query = $this->modelClass::find();
            if (!trim((string)Yii::$app->request->get('q', ''))) $query->andWhere(['forum_theme.is_closed' => 0]);
            $provider = ApiList::provider($query, ['title'], ['id', 'user_id', 'created_at', 'last_post', 'title', 'last_comment_text', 'last_comment_username', 'last_comment_created_at', 'first_comment_user_id', 'first_comment_username']);
            $provider->sort->attributes['last_comment_created_at'] = ['asc' => ['last_comment_sort' => SORT_ASC, 'id' => SORT_DESC], 'desc' => ['last_comment_sort' => SORT_DESC, 'id' => SORT_DESC]];
            return $provider;
        };
        $actions['my'] = $actions['index'];
        $actions['my']['prepareDataProvider'] = function () {
            return ApiList::provider($this->modelClass::find()->my(App::user()->identity), ['title'], ['id', 'user_id', 'created_at', 'last_post', 'title', 'last_comment_text', 'last_comment_username', 'last_comment_created_at', 'first_comment_user_id', 'first_comment_username']);
        };
        $actions['create']['scenario'] = 'create';
        $actions['update']['scenario'] = 'update';

        unset($actions['delete']);
        return $actions;
    }

    public function checkAccess($action, $model = null, $params = []): void
    {
        parent::checkAccess($action, $model, $params);

        if (
            ($action === 'delete' && (int)$model->user_id !== (int)App::user()->id)
            || ($action === 'update' && (int)$model->user_id !== (int)App::user()->id)
        ) {
            throw new ForbiddenHttpException(
                Yii::t('forum', sprintf('The %s action is not available.', $action)),
            );
        }
    }
}
