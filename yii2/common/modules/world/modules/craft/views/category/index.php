<?php

use backend\widgets\gridView\columns\NameColumn;
use common\modules\world\modules\craft\models\CraftCategory;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\StringHelper;

/** @var yii\web\View $this */
/** @var common\modules\world\modules\craft\models\CraftCategorySearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('craft', 'Craft Categories');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="craft-category-index">

    <p>
        <?= Html::a(Yii::t('craft', 'Create'), ['update'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\ActionColumn', 'template' => '{view} {update}'],
            'id',
            [
                'attribute' => 'name',
                'class' => NameColumn::class,
            ],
            [
                'attribute' => 'description',
                'format' => 'text',
                'value' => static function (CraftCategory $model) {
                    return StringHelper::truncate($model->description, 70);
                }
            ],
        ],
    ]); ?>


</div>
