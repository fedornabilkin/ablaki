<?php

namespace backend\controllers;

use backend\models\CommissionSearch;
use common\services\CommissionService;
use Yii;
use yii\web\Controller;

class CommissionController extends Controller
{
    /**
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new CommissionSearch();

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $searchModel->search(Yii::$app->request->queryParams),
            'types' => (new CommissionService())->types(),
        ]);
    }
}
