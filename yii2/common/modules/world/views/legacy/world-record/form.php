<?php
use common\modules\world\models\admin\WorldPremisesOfferForm;
use yii\helpers\Html;
use yii\widgets\ActiveForm;
$this->title = ($row ? 'Изменить: ' : 'Создать: ') . $definition['label'];
$this->params['breadcrumbs'][] = ['label' => $definition['label'], 'url' => ['index']];
if ($row) $this->params['breadcrumbs'][] = ['label' => $row['name'], 'url' => ['view', 'id' => $row['id']]];
$this->params['breadcrumbs'][] = $this->title;
$choices = [
    'kind' => ['canopy' => 'Навес', 'workroom' => 'Мастерская', 'house' => 'Дом', 'forge' => 'Кузница', 'workshop' => 'Столярная мастерская', 'warehouse' => 'Большой склад'],
    'delivery' => ['ready' => 'Готовая постройка', 'construction' => 'Строительство'],
];
$hints = [
    'price' => 'Положительная сумма, до четырёх знаков после точки.',
    'base_price' => 'Последующие грядки стоят B, 2B, 3B…',
    'expansion_base_price' => 'Обязательно, если предусмотрено расширение. Дополнительные места стоят B, 2B, 3B…',
    'duration_seconds' => 'Для стройки: 60–604800 секунд. Для готовой постройки: 0.',
    'materials_json' => 'Материалы стройки: [{"item_id": 1, "quantity": 10}]. Для готовой постройки: [].',
    'repair_materials_json' => 'Материалы полного ремонта: [{"item_id": 1, "quantity": 10}]. Без материалов: [].',
    'repair_full_price' => 'Необязательно. Положительная цена полного ремонта. Пусто — без договора ремонта.',
    'requirements_json' => 'Без ограничений: {"all":[]}. Например: {"all":[{"type":"craft_level","category_id":1,"level":5}]}.',
];
?>
<div class="box box-primary"><div class="box-body">
    <p>Новые условия применяются к следующим покупкам. Существующие покупки сохраняют свои условия. При изменении прежнее предложение снимается с публикации.</p>
    <?php $form = ActiveForm::begin(); ?>
        <?= $form->errorSummary($model) ?>
        <?= $form->field($model, 'settlement_id')->dropDownList($settlements, ['prompt' => 'Выберите поселение', 'disabled' => $row !== null]) ?>
        <?php foreach ($model->attributes() as $field): ?>
            <?php
            if ($field === 'settlement_id') continue;
            $input = $form->field($model, $field);
            if (isset($choices[$field])) $input->dropDownList($choices[$field]);
            elseif (substr($field, -5) === '_json' || $field === 'reason') $input->textarea(['rows' => $field === 'reason' ? 2 : 4]);
            elseif ($field === 'repair_for_existing') $input->checkbox();
            elseif (in_array($field, ['area', 'slots', 'expansion_limit', 'duration_seconds'], true)) $input->input('number', ['min' => $field === 'duration_seconds' ? 0 : 1, 'max' => $field === 'duration_seconds' ? 604800 : 4]);
            else $input->textInput(['maxlength' => in_array($field, ['name', 'code'], true) ? 120 : 40]);
            if (isset($hints[$field])) $input->hint($hints[$field]);
            echo $input;
            ?>
        <?php endforeach ?>
        <?= Html::submitButton('Предварительный просмотр', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Отмена', $row ? ['view', 'id' => $row['id']] : ['index'], ['class' => 'btn btn-default']) ?>
    <?php ActiveForm::end(); ?>
</div></div>
