<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ForumTheme $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="forum-theme-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->field($model, 'title')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'is_private')->checkbox(['role' => 'switch']) ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('forum', 'Save'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
