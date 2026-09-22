<?php

namespace common\modules\games\apiControllers;

use api\filters\Auth;
use common\helpers\App;
use common\modules\games\apiActions\duel\DeleteAction;
use common\modules\games\models\GameDuel;
use common\modules\games\service\DuelService;
use Yii;
use yii\base\UserException;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\BadRequestHttpException;

class DuelController extends ActiveController
{
    use GameHistoryTrait;
    use GameCancellationTrait;
    /** @var GameDuel */
    public $modelClass = GameDuel::class;

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
            $query = $this->lobbyQuery('my')->with('user.person');
            $this->applyStakeFilter($query);
            return new ActiveDataProvider([
                'query' => $query,
            ]);
        };

        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            return $this->prepareHistoryList();
        };

        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            $query = $this->lobbyQuery('available')->with('user.person');
            $this->applyStakeFilter($query);
            return new ActiveDataProvider([
                'pagination' => false,
                'query' => $query->limit(20)->orderBy(['id' => SORT_ASC]),
            ]);
        };

        unset($actions['create'], $actions['view'], $actions['update']);
        unset($actions['delete'], $actions['remove']);
        return $actions;
    }

    /**
     * Создание схватки: ставка kon, скрытые удар u1 и блок b1.
     *
     * @return GameDuel
     * @throws BadRequestHttpException|UserException
     */
    public function actionCreate()
    {
        $model = new GameDuel();
        $model->load(Yii::$app->request->post(), '');

        if (!$model->validate()) {
            $errors = $model->getFirstErrors();
            throw new BadRequestHttpException(reset($errors));
        }

        (new DuelService())->create($model, App::user()->identity->person);

        App::response()->setStatusCode(201);

        return $model;
    }

    /**
     * Ход соперника: удар u2 и блок b2, схватка разыгрывается сразу.
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

        (new DuelService())->play($model, App::user()->identity->person);

        return [
            'gamer' => Yii::$app->user->identity,
            'game' => $model,
        ];
    }

    /**
     * @param integer $id
     * @return GameDuel the loaded model
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
