<?php

namespace common\modules\games\apiControllers;

use api\filters\Auth;
use common\helpers\App;
use common\modules\games\apiActions\five\DeleteAction;
use common\modules\games\models\GameFive;
use common\modules\games\service\FiveService;
use Yii;
use yii\base\UserException;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\BadRequestHttpException;

class FiveController extends ActiveController
{
    /** @var GameFive */
    public $modelClass = GameFive::class;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            Auth::class => [
                'class' => Auth::class,
            ],
        ]);
    }

    public function checkAccess($action, $model = null, $params = []): void
    {
        return;
    }

    public function actions(): array
    {
        $actions = parent::actions();

        $actions['delete'] = [
            'class' => DeleteAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        $actions['my'] = $actions['index'];
        $actions['history'] = $actions['index'];

        $actions['my']['prepareDataProvider'] = function ($action, $filter) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->with(['user', 'userGamer'])
                    ->listMyGame(App::user()->identity)
                    ->orderBy(['updated_at' => SORT_DESC]),
            ]);
        };

        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->orderBy(['updated_at' => SORT_DESC])
                    ->with(['user', 'userGamer'])
                    ->listHistory(App::user()->identity),
            ]);
        };

        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            return new ActiveDataProvider([
                'pagination' => false,
                'query' => $this->modelClass::find()
                    ->limit(20)
                    ->orderBy(['id' => SORT_ASC])
                    ->with('user')
                    ->listGame(App::user()->identity),
            ]);
        };

        unset($actions['create'], $actions['update']);
        return $actions;
    }

    /**
     * Создание партии: ставка kon и скрытый первый ход ball.
     *
     * @return GameFive
     * @throws BadRequestHttpException|UserException
     */
    public function actionCreate()
    {
        $model = new GameFive();
        $model->load(Yii::$app->request->post(), '');

        if (!$model->validate()) {
            $errors = $model->getFirstErrors();
            throw new BadRequestHttpException(reset($errors));
        }

        (new FiveService())->create($model, App::user()->identity->person);

        App::response()->setStatusCode(201);

        return $model;
    }

    /**
     * Ход в партии: вступление в свободную игру, ответ на раунд
     * или скрытый ход создателя в новом раунде.
     *
     * @param int $id
     * @return array
     * @throws UserException
     */
    public function actionPlay(int $id)
    {
        $model = $this->findModel($id);
        $model->setScenario($model::SCENARIO_PLAY);

        if (!$model->load(Yii::$app->request->post(), '') || !$model->validate()) {
            App::response()->setStatusCode(422);
            return ['errors' => $model->getErrors()];
        }

        $hod = (new FiveService())->move(
            $model,
            App::user()->identity->person,
            (int)$model->ball
        );

        return [
            'gamer' => Yii::$app->user->identity,
            'game' => $model,
            'hod' => $hod,
        ];
    }

    /**
     * @param integer $id
     * @return GameFive the loaded model
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
}
