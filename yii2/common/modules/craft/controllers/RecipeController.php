<?php

namespace common\modules\craft\controllers;

use common\modules\craft\models\CraftRecipe;
use common\modules\craft\models\CraftRecipeItem;
use common\modules\craft\models\CraftRecipeSearch;
use common\modules\craft\service\FilterService;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * RecipeController implements the CRUD actions for CraftRecipe model.
 */
class RecipeController extends AdminController
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
     * Lists all CraftRecipe models.
     *
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new CraftRecipeSearch();
        $dataProvider = $searchModel->search($this->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @param $id
     * @return string|Response
     */
    public function actionUpdate($id = null)
    {
        return $this->redirect(['/craft/catalog/edit','group'=>'recipes','code'=>$id ? trim($this->findModel($id)->code) : '']);

    }

    /**
     * @param $id
     * @return Response
     */
    public function actionDelete($id)
    {
//        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * @param $id
     * @return CraftRecipe|null
     * @throws NotFoundHttpException
     */
    protected function findModel($id)
    {
        if (($model = CraftRecipe::findOne(['id' => $id])) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('craft', 'The requested page does not exist.'));
    }
}
