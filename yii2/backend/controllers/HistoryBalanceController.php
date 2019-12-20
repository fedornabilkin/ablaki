<?php

namespace backend\controllers;

use Yii;
use common\models\HistoryBalance;
use backend\models\HistoryBalanceSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;

/**
 * HistoryBalanceController implements the CRUD actions for HistoryBalance model.
 */
class HistoryBalanceController extends Controller
{

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
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
    public function actionIndex()
    {
        $searchModel = new HistoryBalanceSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }


}
