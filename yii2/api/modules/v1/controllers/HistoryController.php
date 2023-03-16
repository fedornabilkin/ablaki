<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 21:54
 */

namespace api\modules\v1\controllers;

use api\modules\v1\models\history\HistoryBalance;
use api\modules\v1\models\history\HistoryRating;
use api\modules\v1\traites\AuthTrait;
use common\helpers\App;
use common\services\history\HistoryService;
use yii\base\DynamicModel;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\rest\ActiveController;

class HistoryController extends ActiveController
{
    use AuthTrait {
        behaviors as useAuthBehavior;
    }

    public function behaviors()
    {

        return array_merge($this->useAuthBehavior(), [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['secret'],
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['secret'],
                        'roles' => ['@'],
                    ],
                ],
            ],
        ]);

    }

    public $modelClass = HistoryBalance::class;

    public function actions(): array
    {
        $actions = parent::actions();

        $actions['index']['dataFilter'] = $this->filter();

        $actions['balance'] = $actions['index'];
        $actions['rating'] = $actions['index'];

        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->select(['type', 'credit_up', 'created_at'])
                    ->orderBy(['id' => SORT_DESC])
                    ->byEveryday()
                    ->with(['user'])
                    ->andFilterWhere($filter),
            ]);
        };

        $actions['balance']['modelClass'] = HistoryBalance::class;
        $actions['balance']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->orderBy(['id' => SORT_DESC])
                    ->with(['user'])
                    ->my(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        $this->modelClass = HistoryRating::class;
        $actions['rating']['prepareDataProvider'] = $actions['balance']['prepareDataProvider'];

        unset($actions['create'], $actions['update'], $actions['view'], $actions['delete']);

        return $actions;
    }

    public function actionBalanceType()
    {
        return (new HistoryService())
            ->groupBalanceTypes()
            ->my(App::user()->identity)
            ->all();
    }

    public function actionRatingType()
    {
        return (new HistoryService())->groupRatingTypes()->all();
    }

    /**
     * @return array
     */
    private function filter(): array
    {
        return [
            'class' => ActiveDataFilter::class,
            'searchModel' => function () {
                return (new DynamicModel(['type' => null]))
                    ->addRule('type', 'string');
            },
        ];
    }
}
