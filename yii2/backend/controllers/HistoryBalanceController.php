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
    public const HT_EVERYDAY = 'everyday';
    public const HT_OREL = 'game_orel';
    public const HT_SAPER = 'game_saper';
    public const HT_DUEL = 'game_duel';
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
    public static function getSortLabels() {
        return [
            self::HT_EVERYDAY  => 'everyday',
            self::HT_OREL => 'game_orel',
            self::HT_SAPER  => 'game_saper',
            self::HT_DUEL => 'game_duel',
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
