<?php

use backend\widgets\gridView\columns\UserColumn;
use common\modules\craft\widgets\columns\CraftItemColumn;
use yii\grid\GridView;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftInventorySearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('craft', 'Craft Inventories');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="craft-inventory-index">

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [

            'id',
            [
                'attribute' => 'user_id',
                'class' => UserColumn::class,
            ],
            [
                'attribute' => 'item_id',
                'class' => CraftItemColumn::class,
            ],
            'item_quantity',
            'slot',
        ],
    ]) ?>

</div>
