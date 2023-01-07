<?php

namespace backend\controllers;

use backend\models\history\HistoryBalanceSearch;
use backend\models\history\HistoryRatingSearch;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * HistoryController implements the CRUD actions for HistoryBalance model.
 */
class HistoryController extends Controller
{

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all HistoryBalance models.
     * @return mixed
     */
    public function actionBalance()
    {
        $searchModel = new HistoryBalanceSearch();

        return $this->render('balance', [
            'searchModel' => $searchModel,
            'dataProvider' => $searchModel->search(Yii::$app->request->queryParams),
        ]);
    }

    public function actionRating()
    {
        $searchModel = new HistoryRatingSearch();

        return $this->render('rating', [
            'searchModel' => $searchModel,
            'dataProvider' => $searchModel->search(Yii::$app->request->queryParams),
        ]);
    }
}
