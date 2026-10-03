<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
$this->title = 'Склад: ' . $row['name'];
$this->params['breadcrumbs'][] = ['label' => 'Склады', 'url' => ['index']];
?>
<div class="box box-primary"><div class="box-body">
    <p>Доступно <?= (int)$row['capacity'] ?> ячеек. Купленная вместимость сохраняется. Новая цена применяется к следующим расширениям.</p>
    <?php $form = ActiveForm::begin(); ?><?= $form->errorSummary($model) ?>
    <?= $form->field($model, 'revision')->hiddenInput()->label(false) ?>
    <?= $form->field($model, 'max_capacity')->input('number', ['min' => $row['capacity'], 'max' => 1000, 'step' => 1])->label('Предел вместимости') ?>
    <?= $form->field($model, 'base_price')->textInput()->label('Базовая цена ячейки, Cr')->hint('Цена = базовая цена × номер дополнительной ячейки.') ?>
    <?= $form->field($model, 'reason')->textarea()->label('Причина изменения') ?>
    <?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?><?php ActiveForm::end(); ?>
</div></div>
