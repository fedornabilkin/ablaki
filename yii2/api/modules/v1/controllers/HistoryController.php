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
use yii\base\DynamicModel;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;

class HistoryController extends ActiveController
{
    use AuthTrait;

    public $modelClass = HistoryBalance::class;

    public function actions(): array
    {
        $actions = parent::actions();

        $actions['index']['dataFilter'] = $this->getFilter();

        $actions['balance'] = $actions['index'];
        $actions['balance']['modelClass'] = HistoryBalance::class;
        $actions['rating'] = $actions['index'];
        $actions['rating']['modelClass'] = HistoryRating::class;

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
