<?php

namespace backend\controllers;

use backend\models\HistoryBalanceSearch;
use common\models\HistoryBalance;
use Yii;
use common\models\user\Person;
use backend\models\PersonSearch;
use yii\data\ActiveDataProvider;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;

/**
 * PersonController implements the CRUD actions for Person model.
 */
class PersonController extends Controller
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
     * Lists all Person models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new PersonSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionView($id)
    {
        $model = $this->findModel($id);
        $searchModel  = new  HistoryBalanceSearch;
	    $dataProvider = $searchModel->search(\Yii::$app->getRequest()->get());
        $dataProvider = new ActiveDataProvider([
            'query' => HistoryBalance::find()
                ->andWhere(['user_id' => $id])
                ->orderBy(['id' => SORT_DESC])
                ->limit(30)
        ]);
        return $this->render('view', [
            'model' => $model,
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    protected function findModel($id)
    {
        if (($model = PersonSearch::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
