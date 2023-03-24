<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftRecipe $model */
/* @var $categoryFilter array */
/* @var $itemFilter array */
/* @var array $recipeItems */

$title = $model->id ? 'Update:' : 'Create';
$this->title = Yii::t('craft', $title . ' {name}', ['name' => $model->name]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('craft', 'Recipes'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->name, 'url' => ['update', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('craft', 'Update');
?>
<div class="craft-recipe-update">

    <p>
        <?= Html::a(Yii::t('craft', 'Create'), ['update'], ['class' => 'btn btn-success']) ?>
        <?= !$model->id ? '' :
            Html::a(Yii::t('craft', 'Delete: {name}', ['name' => $model->name]),
                ['delete', 'id' => $model->id],
                [
                    'class' => 'btn btn-danger',
                    'data' => [
                        'confirm' => Yii::t('craft', 'Are you sure you want to delete this item?'),
                        'method' => 'post',
                    ],
                ]
            )
        ?>
    </p>

    <?= $this->render('_form', [
        'model' => $model,
        'categoryFilter' => $categoryFilter,
        'itemFilter' => $itemFilter,
        'recipeItems' => $recipeItems,
    ]) ?>

</div>
