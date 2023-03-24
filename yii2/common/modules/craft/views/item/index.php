<?php

use backend\widgets\gridView\columns\NameColumn;
use common\modules\craft\models\CraftItem;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\StringHelper;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftItemSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var array $categoryFilter */

$this->title = Yii::t('craft', 'Craft Items');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="craft-item-index">

    <p>
        <?= Html::a(Yii::t('craft', 'Create'), ['update'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [

            'id',
            [
                'attribute' => 'name',
                'class' => NameColumn::class,
            ],
            [
                'attribute' => 'description',
                'format' => 'raw',
                'value' => static function (CraftItem $model) {
                    return StringHelper::truncate($model->description, 70);
                }
            ],
            [
                'attribute' => 'crafting_time',
                'value' => static function (CraftItem $model) {
                    return date('H:i:s', $model->crafting_time);
                },
            ],
            'rare',
            // virtual field
            [
                'attribute' => 'categoryName',
                'filter' => $categoryFilter,
            ],
            [
                'attribute' => 'active',
                'format' => 'raw',
                'filter' => ['On', 'Off'],
                'value' => static function ($model) {
                    return $model->active ? 'On' : 'Off';
                },
            ],
        ],
    ]); ?>


</div>
