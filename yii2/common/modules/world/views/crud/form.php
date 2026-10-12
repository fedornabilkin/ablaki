<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use common\modules\world\models\TemplateSearch;
use common\modules\world\models\NodeTemplate;
$this->title = ($model->isNewRecord ? 'Создание: ' : 'Редактирование: ') . $this->context->title;
if ($model instanceof NodeTemplate && $model->parentIds === null) $model->parentIds = $model->getAllowedParents()->select('id')->column();
?>
<h1><?= Html::encode($this->title) ?></h1>
<?php $form = ActiveForm::begin(); ?>
<?= $form->errorSummary($model) ?>
<?php if ($model instanceof \common\modules\world\models\LockedRecord): ?>
<?= Html::hiddenInput('formVersion', $model->formVersion ?? $model->version()) ?>
<?php endif; ?>
<?php if ($model instanceof NodeTemplate): ?>
<?= $this->render('_template-editor', ['model' => $model, 'form' => $form]) ?>
<?php else: ?>
<?php foreach ($this->context->fields as $attribute): ?>
<?php $field = $form->field($model, $attribute); ?>
<?php if ($attribute === 'template_id' || $attribute === 'parentIds'): ?>
<?= $field->dropDownList(TemplateSearch::options(), $attribute === 'parentIds' ? ['multiple' => true] : ['prompt' => 'Выберите шаблон']) ?>
<?php elseif (in_array($attribute, ['enabled', 'player_buildable'], true)): ?>
<?= $field->checkbox() ?>
<?php elseif ($attribute === 'status'): ?>
<?= $field->dropDownList(['draft' => 'Черновик', 'active' => 'Активно', 'closed' => 'Завершено']) ?>
<?php elseif ($attribute === 'visibility'): ?>
<?= $field->dropDownList(['public' => 'Общий доступ', 'private' => 'Только владелец']) ?>
<?php elseif (substr($attribute, -5) === '_json' || $attribute === 'description'): ?>
<?= $field->textarea(['rows' => 5]) ?>
<?php else: ?>
<?= $field->textInput(\backend\support\EntityDisplay::creditField($attribute) ? ['value' => \backend\support\EntityDisplay::credits($model->$attribute), 'data-credit-original' => $model->$attribute, 'data-credit-display' => \backend\support\EntityDisplay::credits($model->$attribute), 'placeholder' => \backend\support\EntityDisplay::credits('0.0000'), 'inputmode' => 'decimal'] : []) ?>
<?php endif; endforeach; ?>
<?php if ($model instanceof \common\modules\world\models\Node): ?>
<?php $worldRoot = (int)$model->hierarchy_level === 1;
if ($model->worldTimeRate === null) $model->worldTimeRate = \common\modules\world\models\WorldTime::rate($worldRoot ? (int)$model->id : (int)$model->root_id); ?>
<?= $form->field($model, 'worldTimeRate')->input('number', ['min' => 1, 'max' => 86400, 'disabled' => !$worldRoot])->hint('Общий коэффициент мира редактируется у корневого объекта. 1:60 — одна реальная секунда равна минуте мира. Принятые длительности строек сохраняются.') ?>
<?= $this->render('_node-cells', ['model' => $model, 'form' => $form]) ?>
<?php endif; ?>
<?php endif; ?>
<?= $this->render('_record-fields', ['model' => $model, 'form' => $form]) ?>
<?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?>
<?= Html::a('Отмена', ['index'], ['class' => 'btn btn-default']) ?>
<?php ActiveForm::end(); ?>
