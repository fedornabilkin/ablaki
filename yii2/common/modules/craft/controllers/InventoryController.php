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
class InventoryController extends AdminController
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
        throw new \yii\web\GoneHttpException('Используйте защищённый API крафта.');
    }

    public function actionAddItem()
    {
        throw new \yii\web\GoneHttpException('Служебная выдача предметов отключена.');
    }
}
