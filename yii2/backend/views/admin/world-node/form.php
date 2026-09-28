<?php
use common\modules\world\model\WorldNodeForm;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = ($id ? 'Изменить: ' . $model->name : 'Создать объект') . ' · ' . WorldNodeForm::TYPES[$model->type()];
$this->params['breadcrumbs'][] = ['label' => WorldNodeForm::TYPES[$model->type()], 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$choices = WorldNodeForm::choices();
?>
<div class="box box-primary"><div class="box-body">
    <p>Изменения сохраняются после предварительного просмотра и подтверждения. Причина попадёт в журнал.</p>
    <p>Для объектов с купленными правами, имуществом или финансами изменение игровых параметров выполняется соответствующими игровыми действиями. Название и координаты можно исправить здесь.</p>
    <?php $form = ActiveForm::begin(); ?>
        <?= $form->errorSummary($model) ?>
        <?= $form->field($model, 'revision')->hiddenInput()->label(false) ?>
        <?= $form->field($model, 'name')->textInput(['maxlength' => 120]) ?>
        <div class="row">
            <div class="col-md-6"><?= $form->field($model, 'code')->textInput(['maxlength' => 80, 'readonly' => (bool)$id])->hint('Латинские строчные буквы, цифры и дефис. После создания код постоянный.') ?></div>
            <div class="col-md-6"><?= $form->field($model, 'slug')->textInput(['maxlength' => 80]) ?></div>
        </div>
        <?php if ($model->type() !== 'WORLD'): ?>
            <?= $form->field($model, 'parent_id')->input('number', ['min' => 1])->hint('ID можно посмотреть в списке родительского раздела. Мир → регион → поселение → постройка или участок; комната — внутри постройки, грядка — внутри огорода.') ?>
        <?php endif ?>
        <?= $form->field($model, 'owner_user_id')->input('number', ['min' => 1])->hint('Пустое значение — системный объект. Создание объекта не выдаёт вещи, кредиты или платные права.') ?>
        <?= $form->field($model, 'visibility')->dropDownList($choices['visibility']) ?>
        <div class="row">
            <?php foreach (['position_x', 'position_y', 'position'] as $field): ?>
                <div class="col-md-4"><?= $form->field($model, $field)->input('number', ['min' => -1000000, 'max' => 1000000]) ?></div>
            <?php endforeach ?>
        </div>
        <?= $form->field($model, 'footprint')->textarea(['rows' => 3, 'maxlength' => 2048])->hint('Необязательно. Абсолютные вершины JSON, например [{"x":0,"y":0},{"x":2,"y":0},{"x":2,"y":1},{"x":0,"y":1}]. Пустое поле = одна ячейка.') ?>
        <?php foreach ($model->detailFields() as $field): ?>
            <?= isset($choices[$field]) ? $form->field($model, $field)->dropDownList($choices[$field]) : ($field === 'climate' ? $form->field($model, $field)->textInput(['maxlength' => 24]) : $form->field($model, $field)->input('number')) ?>
        <?php endforeach ?>
        <?= $form->field($model, 'reason')->textarea(['rows' => 2, 'maxlength' => 255]) ?>
        <?= Html::submitButton('Предварительный просмотр', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Отмена', $id ? ['view', 'id' => $id] : ['index'], ['class' => 'btn btn-default']) ?>
    <?php ActiveForm::end(); ?>
</div></div>
