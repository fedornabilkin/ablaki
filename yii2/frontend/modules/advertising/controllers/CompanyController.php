<?php

namespace frontend\modules\advertising\controllers;

use common\actions\TestAction;
use common\controllers\FrontendController;
use frontend\modules\advertising\assets\AdvertisingAsset;
use frontend\modules\advertising\models\Advertising;
use frontend\modules\advertising\models\AdvertisingSearch;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\NotFoundHttpException;

/**
 * CompanyController implements the CRUD actions for Advertising model.
 */
class CompanyController extends FrontendController
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['test','index','view','create','update','delete','switch'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        $view = $this->getView();
        $view->registerAssetBundle(AdvertisingAsset::class);
        return parent::beforeAction($action);
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return array_merge(parent::actions(), [
            'test' => [
                'class' => TestAction::class,
            ],
        ]);
    }

    /**
     * Lists all Advertising models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new AdvertisingSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Advertising model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new Advertising model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Advertising();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing Advertising model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing Advertising model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionSwitch($id)
    {
        $model = $this->findModel($id);
        $post = Yii::$app->request->post();
        Yii::debug(intval($post['status']), 'qwe');
        $post['status'] = ($post['status'] == 'true') ? 1 : 0;

        Yii::debug($post['status'], 'qwe');

        $params['text'] = 'success';
        if (!$model->load($post, '') or !$model->save()) {
            $params['error'] = true;
            $params['text'] = $model->getFirstError('status');
        }

        return $this->ajaxResponse($params);
    }

    /**
     * Deletes an existing Advertising model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        //todo удалять только без баланса и выключенную, плюс проверить принадлежность к юзеру или админу
        //todo и то не удалять, а скрывать
        //$this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds the Advertising model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Advertising the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function findModel($id)
    {
        if (($model = Advertising::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('advertising', 'The requested page does not exist.'));
    }
}
