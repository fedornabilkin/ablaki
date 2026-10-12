<?php
use yii\helpers\Html;
$shown = $shown ?? $this->context->fields;
?>
<fieldset class="admin-wide admin-record-fields">
    <legend>Все поля записи</legend>
    <p class="text-muted">Служебные и вычисляемые значения показаны для чтения. Настройки редактируются в общих полях формы выше.</p>
    <div class="admin-settings">
    <?php foreach ($model->attributes() as $attribute): if (in_array($attribute, $shown, true)) continue; ?>
        <?php $options = ['disabled' => true]; if (\backend\support\EntityDisplay::creditField($attribute)) $options['value'] = \backend\support\EntityDisplay::credits($model->$attribute); $field = $form->field($model, $attribute); ?>
        <?= substr($attribute, -5) === '_json' ? $field->textarea($options + ['rows' => 4]) : $field->textInput($options) ?>
    <?php endforeach ?>
    </div>
</fieldset>
