<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:02
 */

namespace common\modules\forum\api\controllers;

use api\modules\v1\models\forum\Comment;
use common\helpers\App;
use Yii;
use yii\base\DynamicModel;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\ForbiddenHttpException;

class CommentController extends ActiveController
{
    public $modelClass = Comment::class;

    public function actions()
    {
        $actions = parent::actions();
        $actions['index']['dataFilter'] = $this->filter();

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
