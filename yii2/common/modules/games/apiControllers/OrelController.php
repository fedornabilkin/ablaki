<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 20:27
 */

namespace common\modules\games\apiControllers;

use api\filters\Auth;
use common\helpers\App;
use common\middleware\DataMiddleware;
use common\middleware\HistoryCommissionMiddleware;
use common\modules\games\apiActions\orel\CreateAction;
use common\modules\games\apiActions\orel\DeleteAction;
use common\modules\games\apiActions\orel\RemoveAction;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\CheckNotMyGameMiddleware;
use common\modules\games\middleware\GamerCheckCreditMiddleware;
use common\modules\games\middleware\orel\PlayMiddleware;
use common\modules\games\middleware\orel\SwitchCreatorMiddleware;
use common\modules\games\models\GameOrel;
use Yii;
use yii\base\DynamicModel;
use yii\base\UserException;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\db\Exception;
use yii\db\Query;
use yii\rest\ActiveController;

class OrelController extends ActiveController
{
    /** @var GameOrel */
    public $modelClass = GameOrel::class;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'authenticator' => [
                'class' => Auth::class,
            ],
        ]);
    }

    public function actions(): array
    {
        $actions = parent::actions();

        $actions['delete'] = [
            'class' => DeleteAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        $actions['remove'] = [
            'class' => RemoveAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        $actions['create'] = [
            'class' => CreateAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        $actions['my'] = $actions['index'];
        $actions['history'] = $actions['index'];

        $actions['my']['dataFilter'] = $this->getFilter();
        $actions['my']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->with('user')
                    ->free()
                    ->my(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        $actions['history']['dataFilter'] = $this->getFilter();
        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->with('user')
                    ->notFree()
                    ->history(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        $actions['index']['dataFilter'] = $this->getFilter();
        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'pagination' => false,
                'query' => $this->modelClass::find()
                    ->limit(20)
                    ->orderBy(['id' => SORT_ASC])
                    ->with('user')
                    ->free()
                    ->notMy(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        unset($actions['view'], $actions['update']);
        return $actions;
    }

    public function actionKonCount()
    {
        return (new Query())->select(['kon', 'COUNT(*) AS count'])
            ->from($this->modelClass::tableName())
            ->andWhere(['user_gamer' => 0])
            ->andWhere(['!=', 'user_id', App::user()->getId()])
            ->groupBy(['kon'])
            ->orderBy(['kon' => SORT_ASC])
            ->all();
    }

    /**
     * @param int $id
     * @return array|void
     * @throws Exception
     * @throws UserException
     */
    public function actionPlay(int $id)
    {
        $model = $this->findModel($id);
        $model->setScenario($model::SCENARIO_PLAY);

        if (!$model->load(Yii::$app->request->post(), '') || !$model->validate()) {
            return $model->getErrors();
        }

        $data = new DataMiddleware([
            'game' => $model,
            'user' => Yii::$app->user->identity->person,
        ]);

        $middleware = new GamerCheckCreditMiddleware();
        $middleware::$data = $data;

        $middleware
            ->linkWith(new CheckNotMyGameMiddleware())
            ->linkWith(new CheckFreeGameMiddleware())
            ->linkWith(new PlayMiddleware())
            ->linkWith(new SwitchCreatorMiddleware())
            ->linkWith(new HistoryCommissionMiddleware());

        if (!$middleware->check()) {
            $errors = $middleware->getErrors();
            throw new UserException(Yii::t('games', $errors[0]));
        }

        return [
            'gamer' => Yii::$app->user->identity,
            'game' => $model,
        ];
    }

    /**
     * Finds the GameOrel model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return GameOrel the loaded model
     * @throws UserException if the model cannot be found
     */
    private function findModel($id)
    {
        $model = call_user_func([$this->modelClass, 'findOne'], $id);
        if (!$model) {
            throw new UserException(Yii::t('games', 'The requested model does not exist.'));
        }
        return $model;
    }

    /**
     * @return array
     */
    private function getFilter(): array
    {
        return [
            'class' => ActiveDataFilter::class,
            'searchModel' => function () {
                return (new DynamicModel(['kon' => null]))
                    ->addRule('kon', 'number');
            },
        ];
    }
}
