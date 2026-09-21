<?php

namespace common\modules\craft\controllers;

use common\modules\craft\models\CraftItem;
use common\modules\craft\models\CraftItemSearch;
use common\modules\craft\service\FilterService;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * ItemController implements the CRUD actions for CraftItem model.
 */
class ItemController extends AdminController
{
    /**
     * @inheritDoc
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
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
     * Lists all CraftItem models.
     *
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new CraftItemSearch();
        $dataProvider = $searchModel->search($this->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'categoryFilter' => (new FilterService())->categoryFilter(),
        ]);
    }

    /**
     * Displays a single CraftItem model.
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
     * @param $id
     * @return string|Response
     */
    public function actionUpdate($id = null)
    {
        return $this->redirect(['/craft/catalog/edit','group'=>'items','code'=>$id ? trim($this->findModel($id)->code) : '']);

    }

    /**
     * Deletes an existing CraftItem model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param int $id #
     * @return Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        throw new \yii\web\GoneHttpException('Отключите предмет в каталоге: инвентари и история должны сохраняться.');
    }

    /**
     * Finds the CraftItem model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param int $id #
     * @return CraftItem the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = CraftItem::findOne(['id' => $id])) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('craft', 'The requested page does not exist.'));
    }
}
