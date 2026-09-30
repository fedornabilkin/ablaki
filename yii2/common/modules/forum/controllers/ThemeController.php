<?php

namespace common\modules\forum\controllers;

use common\modules\forum\models\ForumTheme;
use common\modules\forum\models\ThemeSearch;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * ThemeController implements the CRUD actions for ForumTheme model.
 */
class ThemeController extends Controller
{
    /**
     * @inheritDoc
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                'access' => [
                    'class' => \yii\filters\AccessControl::class,
                    'only' => ['create', 'update', 'delete'],
                    'rules' => [
                        ['allow' => true, 'actions' => ['create'], 'roles' => ['@']],
                        ['allow' => true, 'actions' => ['update', 'delete'], 'roles' => ['@'], 'matchCallback' => function () {
                            return (int)$this->findModel(Yii::$app->request->get('id'))->user_id === (int)Yii::$app->user->id;
                        }],
                        ['allow' => true, 'actions' => ['update'], 'roles' => ['/forum/theme/update']],
                        ['allow' => true, 'actions' => ['delete'], 'roles' => ['/forum/theme/delete']],
                    ],
                ],
                'verbs' => [
                    'class' => VerbFilter::class,
                    'actions' => [
                        'delete' => ['POST'],
                    ],
                ],
            ]
        );
    }

    /**
     * Lists all ForumTheme models.
     *
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new ThemeSearch();
        $dataProvider = $searchModel->search($this->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single ForumTheme model.
     * @param int $id #
     * @return string
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new ForumTheme model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return string|Response
     */
    public function actionCreate()
    {
        $model = new ForumTheme();

        if ($this->request->isPost) {
            $model->view = 0;
            $input = $this->request->post($model->formName(), []);
            $model->setAttributes(array_intersect_key((array)$input, array_flip(['title', 'is_private'])));
            if ($model->save()) {
                return $this->redirect(['view', 'id' => $model->id]);
            }
        } else {
            $model->loadDefaultValues();
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing ForumTheme model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param int $id #
     * @return string|Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if ($this->request->isPost) {
            $input = $this->request->post($model->formName(), []);
            $model->setAttributes(array_intersect_key((array)$input, array_flip(['title', 'is_private', 'is_closed'])));
            if ($model->save()) return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes an existing ForumTheme model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param int $id #
     * @return Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        \common\modules\forum\services\ThemeDeleteService::delete(Yii::$app->db, (int)$id);

        return $this->redirect(['index']);
    }

    /**
     * Finds the ForumTheme model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param int $id #
     * @return ForumTheme the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = ForumTheme::findOne(['id' => $id])) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('forum', 'The requested page does not exist.'));
    }
}
