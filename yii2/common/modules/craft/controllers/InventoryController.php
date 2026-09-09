<?php

namespace common\modules\craft\controllers;

use common\helpers\App;
use common\modules\craft\models\CraftInventorySearch;
use common\modules\craft\models\CraftItem;
use common\modules\craft\models\CraftRecipe;
use common\modules\craft\service\CraftService;
use common\modules\craft\service\InventoryService;
use yii\web\Controller;

/**
 * InventoryController implements the CRUD actions for CraftInventory model.
 */
class InventoryController extends Controller
{
    /**
     * Lists all CraftInventory models.
     *
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new CraftInventorySearch();
        $dataProvider = $searchModel->search($this->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionCraft()
    {
        $recipe = CraftRecipe::findOne(3);

        (new CraftService())->craftItem(App::user()->identity->person, $recipe);

        return $this->redirect(['/craft/inventory']);
    }

    public function actionAddItem()
    {
        $item = CraftItem::findOne(5);
        $item->setQuantity(-2);

        (new InventoryService())->addItem(App::user()->identity->person, $item);

        return $this->redirect(['/craft/inventory']);
    }
}
