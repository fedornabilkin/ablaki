<?php

namespace common\modules\games\apiControllers;

use api\filters\Auth;
use api\components\ApiList;
use common\helpers\App;
use common\modules\games\models\GameFive;
use common\modules\games\service\FiveService;
use Yii;
use yii\base\UserException;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\BadRequestHttpException;

class FiveController extends ActiveController
{
    use GameHistoryTrait;
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

        $actions['my'] = $actions['index'];
        $actions['history'] = $actions['index'];

        $actions['my']['prepareDataProvider'] = function ($action, $filter) {
            return $this->prepareGames(true);
        };

        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            return $this->prepareHistoryList();
        };

        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            return $this->prepareGames(false);
        };

        unset($actions['create'], $actions['update'], $actions['delete']);
        return $actions;
    }

    private function prepareGames(bool $mine): ActiveDataProvider
    {
        $query = $this->modelClass::find()->with(['user.person', 'userGamer.person']);
        if ($mine) $query->listMyGame(App::user()->identity);
        else $query->listGame(App::user()->identity);
        return ApiList::provider($query, [], ['id', 'created_at', 'updated_at', 'kon'], static function ($query, $search) {
            $condition = ApiList::relatedUserCondition(['user_id', 'user_gamer'], $search);
            if (ctype_digit($search)) $condition[] = ['id' => $search];
            $query->andWhere($condition);
        });
    }

    public function actionDelete(int $id): void
    {
        (new FiveService())->cancel($this->findModel($id), App::user()->identity->person);
        App::response()->setStatusCode(204);
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

        $roundId = Yii::$app->request->post('round_id');
        if ($roundId !== null && (!is_scalar($roundId) || !ctype_digit((string)$roundId) || (int)$roundId < 1)) {
            throw new BadRequestHttpException('Invalid round.');
        }
        $hod = (new FiveService())->move(
            $model,
            App::user()->identity->person,
            (int)$model->ball,
            $roundId === null ? null : (int)$roundId
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
            throw new \yii\web\NotFoundHttpException('Game not found.');
        }
        return $model;
    }
}
