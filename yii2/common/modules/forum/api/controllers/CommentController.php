<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:02
 */

namespace common\modules\forum\api\controllers;

use api\modules\v1\models\forum\Comment;
use api\components\ApiList;
use common\helpers\App;
use common\modules\forum\services\CommentGiftService;
use Yii;
use yii\base\DynamicModel;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\db\Query;

class CommentController extends ActiveController
{
    public $modelClass = Comment::class;

    public function actions()
    {
        $actions = parent::actions();
        $actions['index']['dataFilter'] = $this->filter();
        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            return ApiList::provider($this->modelClass::find()->andWhere(['active' => 1])->andFilterWhere($filter ?? []), ['comment']);
        };

        $actions['my'] = $actions['index'];
        $actions['my']['prepareDataProvider'] = function ($action, $filter) {
            return ApiList::provider($this->modelClass::find()->my(App::user()->identity)->andWhere(['active' => 1])->andFilterWhere($filter ?? []), ['comment']);
        };
        $actions['create']['scenario'] = 'create';
        $actions['update']['scenario'] = 'update';
        $actions['options']['resourceOptions'] = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

        unset($actions['delete']); // remove awards and history balance?
        return $actions;
    }

    protected function verbs()
    {
        return array_merge(parent::verbs(), ['gift' => ['POST'], 'gifts' => ['GET', 'HEAD']]);
    }

    public function actionGift($id): array
    {
        try {
            return (new CommentGiftService(Yii::$app->db))->give((int)$id, (int)App::user()->id);
        } catch (\DomainException $error) {
            throw new UnprocessableEntityHttpException('Не удалось передать кредит: проверьте сообщение и доступные средства.');
        }
    }

    public function actionGifts($id): ActiveDataProvider
    {
        if (!$this->modelClass::find()->where(['id' => $id, 'active' => 1])->exists()) throw new NotFoundHttpException();
        $gifts = (new Query())->select(['id' => 'gift.id', 'user_id' => 'gift.user_id', 'username' => 'donor.username', 'created_at' => 'gift.created_at'])
            ->from(['gift' => 'forum_comment_gift'])->innerJoin(['donor' => 'user'], '[[donor.id]] = [[gift.user_id]]')
            ->where(['gift.comment_id' => $id]);
        return ApiList::provider((new Query())->from(['gift_list' => $gifts]), ['username']);
    }

    public function checkAccess($action, $model = null, $params = []): void
    {
        parent::checkAccess($action, $model, $params);

        if ($model !== null && (int)$model->active !== 1) throw new NotFoundHttpException();

        if (
            ($action === 'delete' && $model->user_id !== App::user()->id)
            || ($action === 'update' && $model->user_id !== App::user()->id)
        ) {
            throw new ForbiddenHttpException(
                Yii::t('forum', sprintf('The %s action is not available.', $action)),
            );
        }
    }

    /**
     * @return array
     */
    private function filter(): array
    {
        return [
            'class' => ActiveDataFilter::class,
            'searchModel' => function () {
                return (new DynamicModel(['theme_id' => null, 'user_id' => null]))
                    ->addRule('theme_id', 'number')
                    ->addRule('user_id', 'number');
            },
        ];
    }
}
