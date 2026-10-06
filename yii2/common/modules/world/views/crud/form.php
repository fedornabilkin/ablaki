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
<?= $field->textInput() ?>
<?php endif; endforeach; ?>
<?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?>
<?= Html::a('Отмена', ['index'], ['class' => 'btn btn-default']) ?>
<?php ActiveForm::end(); ?>
