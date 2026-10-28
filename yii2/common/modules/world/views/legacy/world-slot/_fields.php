<?php
use yii\helpers\Html;
foreach (['node_id', 'revision', 'storage_revision'] as $field) echo $form->field($model, $field)->hiddenInput()->label(false);
?>
<?= $form->field($model, 'code')->textInput(['maxlength' => 64]) ?>
<?= $form->field($model, 'slot_type')->dropDownList(['equipment' => 'Станция или сундук', 'station' => 'Только станция', 'chest' => 'Только сундук']) ?>
<?= $form->field($model, 'size')->input('number', ['min' => 1, 'max' => 100]) ?>
<?= $form->field($model, 'exposure_class')->dropDownList(['outdoor' => 'Улица', 'covered' => 'Под навесом', 'indoor' => 'В помещении']) ?>
<?= $form->field($model, 'item_codes')->textarea(['rows' => 2])->hint('Коды из каталога крафта через запятую. Пусто — все предметы выбранного назначения.') ?>
<?= $form->field($model, 'reason')->textarea(['rows' => 2, 'maxlength' => 255]) ?>
