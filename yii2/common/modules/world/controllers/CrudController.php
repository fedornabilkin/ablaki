<?php
namespace common\modules\world\controllers;

use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;

/** Standard Yii forms and providers. Authorization also protects direct module routes outside backend. */
abstract class CrudController extends Controller
{
    public $modelClass;
    public $searchClass;
    public $title;
    public $columns = [];
    public $fields = [];
    public function allowAction(): array { return ['index', 'view', 'create', 'update', 'delete']; }
    public function beforeAction($action)
    {
        if (Yii::$app->id !== 'app-backend' || Yii::$app->user->isGuest || !Yii::$app->user->can('p-admin')) throw new \yii\web\ForbiddenHttpException('Требуются права администратора.');
        return parent::beforeAction($action);
    }
    public function behaviors(): array { return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['delete' => ['POST']]]]; }
    public function getViewPath() { return dirname(__DIR__) . '/views/crud'; }
    public function actionIndex()
    {
        $search = new $this->searchClass();
        return $this->render('index', ['searchModel' => $search, 'dataProvider' => $search->search(Yii::$app->request->queryParams)]);
    }
    public function actionView($id) { return $this->render('view', ['model' => $this->modelClass::requireOne($id)]); }
    public function actionCreate() { $model = new $this->modelClass(); $model->loadDefaultValues(); return $this->edit($model); }
    public function actionUpdate($id) { return $this->edit($this->modelClass::requireOne($id)); }
    protected function edit($model)
    {
        if ($model->load(Yii::$app->request->post())) {
            if ($model instanceof \common\modules\world\models\LockedRecord) $model->formVersion = (string)Yii::$app->request->post('formVersion', '');
            if ($model->save()) return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model]);
    }
    public function actionDelete($id)
    {
        $model = $this->modelClass::requireOne($id);
        if ($model instanceof \common\modules\world\models\NodeTemplate) $model->enabled = 0;
        else $model->status = $model instanceof \common\modules\world\models\WorldEvent ? 'closed' : 'archived';
        if (!$model->save()) Yii::$app->session->setFlash('error', implode(' ', $model->getFirstErrors()));
        return $this->redirect(['view', 'id' => $model->id]);
    }
}
