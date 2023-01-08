<?php

namespace common\modules\exchange\controllers;

use common\modules\exchange\models\CreditTransfer;
use common\modules\exchange\models\CreditTransferSearch;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * TransferController implements the CRUD actions for CreditTransfer model.
 */
class TransferController extends Controller
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
     * Lists all CreditTransfer models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new CreditTransferSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Finds the CreditTransfer model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return CreditTransfer the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = CreditTransfer::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('exchange', 'The requested page does not exist.'));
    }
}
