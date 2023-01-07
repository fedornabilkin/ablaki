<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 21:54
 */

namespace api\modules\v1\controllers;

use api\filters\Auth;
use api\modules\v1\models\history\HistoryBalance;
use api\modules\v1\models\history\HistoryRating;
use common\helpers\App;
use yii\base\DynamicModel;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;

class HistoryController extends ActiveController
{
    public $modelClass = HistoryBalance::class;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            Auth::class => [
                'class' => Auth::class,
            ],
        ]);
    }


    public function actions(): array
    {
        $actions = parent::actions();

        $actions['index']['dataFilter'] = $this->getFilter();

        $actions['balance'] = $actions['index'];
        $actions['rating'] = $actions['index'];
        $this->modelClass = HistoryBalance::class;

        $actions['balance']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->orderBy(['created_at' => SORT_DESC])
                    ->with(['user'])
                    ->my(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        $this->modelClass = HistoryRating::class;
        $actions['rating']['prepareDataProvider'] = $actions['balance']['prepareDataProvider'];


        unset($actions['index'], $actions['create'], $actions['update'], $actions['view'], $actions['delete']);

        return $actions;
    }

    public function actionBalanceType()
    {
        return HistoryBalance::find()
            ->select(['type'])
            ->my(App::user()->identity)
            ->groupBy(['type'])
            ->all();
    }

    public function actionRatingType()
    {
        return HistoryRating::find()
            ->select(['type'])
            ->my(App::user()->identity)
            ->groupBy(['type'])
            ->all();
    }

    /**
     * @return array
     */
    private function getFilter(): array
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
