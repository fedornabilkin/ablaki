<?php

namespace common\modules\craft\controllers;

use common\helpers\App;
use common\modules\craft\models\CraftInventorySearch;
use common\modules\craft\models\CraftItem;
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

    public function actionAddItem()
    {
        $item = CraftItem::findOne(4);
        $item->setQuantity(3);

        (new InventoryService())->addItem(App::user()->identity->person, $item);

        return $this->redirect(['/craft/inventory']);
    }
}
