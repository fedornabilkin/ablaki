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
class RecipeController extends Controller
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
        $model = CraftRecipe::findOne(['id' => $id]) ?? new CraftRecipe();

        $recipeItems = CraftRecipeItem::find()->indexBy('id')->where(['recipe_id' => $model->id])->all();
        $newRecipeItems = $postData = [];

        if ($this->request->isPost && $model->load($this->request->post()) && $model->save()) {


            foreach (Yii::$app->request->post('CraftRecipeItem', []) as $index => $post) {
                if ($post['item_id'] === $model->item_id) {
                    continue;
                }

                if (!empty($recipeItems[$index])) {
                    $recipeItems[$index]->load($post, '');
                    if (!$recipeItems[$index]->validate()) {
                        $recipeItems[$index]->delete();
                        continue;
                    }

                    $recipeItems[$index]->save(false);
                } else {
                    $postData[] = $post;
                    $newRecipeItems[] = new CraftRecipeItem(['recipe_id' => $model->id]);
                }
            }

            if ($model::loadMultiple($newRecipeItems, $postData, '') && $model::validateMultiple($newRecipeItems)) {
                foreach ($newRecipeItems as $source) {
                    $model->link('recipeItems', $source);
                }
            }

            return $this->redirect(['update', 'id' => $model->id]);
        }

        $recipeItems[] = new CraftRecipeItem(['recipe_id' => $model->id]);

        return $this->render('update', [
            'model' => $model,
            'categoryFilter' => (new FilterService())->categoryFilter('id'),
            'itemFilter' => (new FilterService())->itemFilter('id'),
            'recipeItems' => $recipeItems,
        ]);
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
