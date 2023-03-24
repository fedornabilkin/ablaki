<?php

use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\UserColumn;
use common\modules\craft\models\CraftHistory;
use yii\grid\GridView;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftHistorySearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('craft', 'Craft Histories');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="craft-history-index">

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
                'format' => 'raw',
                'value' => static function (CraftHistory $model) {
                    return Html::a($model->item_id . ' ' . $model->item->name, ['item/view', 'id' => $model->item_id]);
                }
            ],
            [
                'attribute' => 'recipe_id',
                'format' => 'raw',
                'value' => static function (CraftHistory $model) {
                    return Html::a($model->recipe_id . ' ' . $model->recipe->name, ['recipe/view', 'id' => $model->recipe_id]);
                }
            ],
            [
                'attribute' => 'created_at',
                'class' => CreatedAtColumn::class
            ],
        ],
    ]); ?>


</div>
