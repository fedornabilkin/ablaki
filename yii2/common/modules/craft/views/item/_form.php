<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var common\modules\craft\models\CraftItem $model */
/** @var yii\widgets\ActiveForm $form */
/* @var array $categoryFilter */
?>

<div class="craft-item-form">

    <?php $form = ActiveForm::begin(); ?>
    <div class="row">
        <div class="col-sm-6">
            <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-sm-3">
            <?= $form->field($model, 'crafting_time')->textInput() ?>
        </div>
        <div class="col-sm-3">
            <?= $form->field($model, 'rare')->textInput() ?>
        </div>

        <div class="col-sm-12">
            <?= $form->field($model, 'description')->textarea(['maxlength' => true, 'rows' => 6]) ?>
        </div>

        <div class="col-sm-3">
            <?= $form->field($model, 'category_id')->dropDownList($categoryFilter) ?>
        </div>
        <div class="col-sm-3">
            <?= $form->field($model, 'active')->checkbox(['checked ' => (bool)$model->active]) ?>
        </div>
        <div class="col-sm-12">
            <div class="form-group">
                <?= Html::submitButton(Yii::t('craft', 'Save'), ['class' => 'btn btn-success']) ?>
            </div>
        </div>

    </div>
    <?php ActiveForm::end(); ?>
</div>
