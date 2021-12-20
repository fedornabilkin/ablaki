<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 20:27
 */

namespace common\modules\games\apiControllers;

use api\filters\Auth;
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
use yii\base\UserException;
use yii\data\ActiveDataProvider;
use yii\db\Exception;
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

        $actions['my']['prepareDataProvider'] = function ($action) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->with('user')
                    ->free()
                    ->my(Yii::$app->user->identity),
            ]);
        };

        $actions['history']['prepareDataProvider'] = function ($action) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->with('user')
                    ->notFree()
                    ->history(Yii::$app->user->identity),
            ]);
        };

        $actions['index']['prepareDataProvider'] = function ($action) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->with('user')
                    ->free()
                    ->notMy(Yii::$app->user->identity),
            ]);
        };

        unset($actions['view'], $actions['update']);
        return $actions;
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
    public function findModel($id)
    {
        $model = call_user_func([$this->modelClass, 'findOne'], $id);
        if (!$model) {
            throw new UserException(Yii::t('games', 'The requested model does not exist.'));
        }
        return $model;
    }
}
