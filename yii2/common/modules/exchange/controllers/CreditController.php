<?php

namespace common\modules\exchange\controllers;

use common\modules\exchange\models\CreditExchange;
use common\modules\exchange\models\CreditExchangeSearch;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * CreditController implements the CRUD actions for CreditExchange model.
 */
class CreditController extends Controller
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
     * Lists all CreditExchange models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new CreditExchangeSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Finds the CreditExchange model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return CreditExchange the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = CreditExchange::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('exchange', 'The requested page does not exist.'));
    }
}
