<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftRecipe $model */
/** @var yii\widgets\ActiveForm $form */
/* @var array $categoryFilter */
/* @var array $itemFilter */
/* @var array $recipeItems */

array_unshift($itemFilter, '');
?>

<div class="craft-recipe-form">

    <?php $form = ActiveForm::begin(); ?>
    <div class="row">
        <div class="col-xs-7">
            <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-xs-5">
            <?= $form->field($model, 'item_id')->dropDownList($itemFilter ?? []) ?>
        </div>

        <div class="col-sm-12">
            <?= $form->field($model, 'description')->textarea(['maxlength' => true, 'rows' => 6]) ?>
        </div>
        <div class="col-xs-12">
            <?= $form->field($model, 'active')->checkbox(['checked ' => (bool)$model->active]) ?>
        </div>

        <div class="col-xs-8">
            <?= $form->field($model, 'category_id')->dropDownList($categoryFilter ?? []) ?>
        </div>

        <div class="col-sm-12">
            <?php foreach ($recipeItems as $index => $source) : ?>
                <div class="row">
                    <div class="col-xs-6">
                        <?= $form->field($source, "[$index]item_id")->dropDownList($itemFilter ?? [])->label('Предмет для крафта') ?>
                    </div>
                    <div class="col-xs-2">
                        <?= $form->field($source, "[$index]item_quantity")->textInput()->label('Количество') ?>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

        <div class="col-sm-12">
            <div class="form-group">
                <?= Html::submitButton(Yii::t('craft', 'Save'), ['class' => 'btn btn-success']) ?>
            </div>
        </div>

    </div>

    <?php ActiveForm::end(); ?>

</div>
