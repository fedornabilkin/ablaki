<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
$this->title = $row ? 'Изменить культуру: ' . $row['name'] : 'Добавить культуру';
$this->params['breadcrumbs'][] = ['label' => 'Культуры', 'url' => ['index']];
?>
<div class="box box-primary"><div class="box-body">
    <p>Публикация создаёт новую версию. Уже посаженные культуры сохраняют сроки и расход ресурсов.</p>
    <?php $form = ActiveForm::begin(); ?><?= $form->errorSummary($model) ?>
    <div class="row"><div class="col-md-6">
        <?= $form->field($model, 'code')->textInput(['readonly' => (bool)$row])->hint('Латинские буквы, цифры и подчёркивание.') ?>
        <?= $form->field($model, 'name') ?>
        <?php foreach (['seed_item_id', 'yield_item_id', 'water_item_id'] as $field): ?>
            <?= $form->field($model, $field)->dropDownList($items, ['prompt' => 'Выберите предмет']) ?>
        <?php endforeach ?>
        <?php foreach (['seed_quantity', 'yield_quantity', 'water_quantity'] as $field): ?><?= $form->field($model, $field)->input('number', ['min' => 0, 'step' => 1]) ?><?php endforeach ?>
    </div><div class="col-md-6">
        <?php foreach (['grow_seconds', 'water_interval_seconds', 'water_window_seconds', 'harvest_window_seconds'] as $field): ?><?= $form->field($model, $field)->input('number', ['min' => 0, 'step' => 1]) ?><?php endforeach ?>
        <p>Пропуск полива уменьшает урожай вдвое один раз за цикл. По окончании срока сбора урожай погибает. Чтобы отключить полив, уберите ресурс воды и установите количество и интервал в 0.</p>
        <?= $form->field($model, 'reason')->textarea(['rows' => 3]) ?>
    </div></div>
    <?= Html::submitButton('Опубликовать', ['class' => 'btn btn-primary']) ?>
    <?php ActiveForm::end(); ?>
</div></div>
