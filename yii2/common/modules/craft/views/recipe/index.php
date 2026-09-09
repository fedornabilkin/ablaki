<?php

use backend\widgets\gridView\columns\NameColumn;
use common\modules\craft\models\CraftRecipe;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\StringHelper;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftRecipeSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('craft', 'Craft Recipes');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="craft-recipe-index">

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
                'value' => static function (CraftRecipe $model) {
                    return StringHelper::truncate($model->description, 70);
                }
            ],
            'category_id',
            'item_id',
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
