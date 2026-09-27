<?php
use yii\grid\GridView;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Каталог построек: ' . $context['settlement_name'];
$this->params['breadcrumbs'][] = ['label' => 'Мир', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $context['settlement_name'], 'url' => ['view', 'id' => $context['settlement_id']]];
$this->params['breadcrumbs'][] = 'Каталог построек';
$id = $context['settlement_id'];
$kinds = ['canopy' => 'Навес', 'workroom' => 'Мастерская', 'house' => 'Дом'];
?>
<div class="box box-primary"><div class="box-body">
    <p>Готовые постройки приобретаются из бюджета участка. Оплата поступает в казну поселения.
        Публикация создаёт новую версию условий; приобретённые постройки сохраняют условия своей покупки.</p>
    <p><?= Html::a('Журнал изменений поселения', ['audit', 'node_id' => $id], ['class' => 'btn btn-default']) ?></p>
    <?= Html::beginForm(['premises', 'id' => $id], 'get', ['class' => 'form-inline']) ?>
        <?= Html::label('Название', 'premises-q') ?>
        <?= Html::textInput('q', $q, ['id' => 'premises-q', 'class' => 'form-control', 'maxlength' => 120]) ?>
        <?= Html::label('Состояние', 'premises-status') ?>
        <?= Html::dropDownList('status', $status, ['' => 'Все', 'published' => 'В продаже', 'withdrawn' => 'Сняты с продажи'], ['id' => 'premises-status', 'class' => 'form-control']) ?>
        <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
    <?= GridView::widget(['dataProvider' => $provider, 'columns' => [
        ['attribute' => 'id', 'label' => 'ID'], ['attribute' => 'name', 'label' => 'Название'],
        ['attribute' => 'template_revision_id', 'label' => 'Версия условий, ID'],
        ['label' => 'Условия', 'format' => 'raw', 'value' => static function (array $row) use ($kinds): string {
            $c = json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $parts = [$kinds[$c['kind']] ?? $c['kind'], 'Цена: ' . $c['price'] . ' Cr', 'Площадь: ' . $c['area'],
                'Оборудование: ' . $c['slots'] . ' / ' . ($c['expansion_limit'] ?? $c['slots']),
                'Мест ночлега: ' . ($c['lodging_places'] ?? 0)];
            if (!empty($c['expansion_base_price'])) $parts[] = 'Расширение: ' . $c['expansion_base_price'] . ' Cr × номер покупки';
            $parts[] = ($c['delivery'] ?? 'ready') === 'construction' ? 'Строительство: ' . $c['duration_seconds'] . ' сек.' : 'Готовая постройка';
            foreach ($c['materials'] ?? [] as $material) $parts[] = $material['name'] . ': ' . $material['quantity'];
            return implode('<br>', array_map([Html::class, 'encode'], $parts));
        }],
        ['attribute' => 'author_user_id', 'label' => 'Автор, ID'],
        ['attribute' => 'status', 'label' => 'Состояние', 'value' => static function (array $row): string { return $row['status'] === 'published' ? 'В продаже' : 'Снято с продажи'; }],
        ['label' => 'Действие', 'format' => 'raw', 'value' => static function (array $row) use ($context, $id): string {
            if (!$context['can_publish'] || $row['status'] !== 'published') return '—';
            return Html::beginForm(['preview', 'id' => $id], 'post')
                . Html::hiddenInput('operation', 'withdraw') . Html::hiddenInput('offer_id', $row['id'])
                . Html::textInput('reason', '', ['class' => 'form-control', 'required' => true, 'maxlength' => 255, 'aria-label' => 'Причина снятия', 'placeholder' => 'Причина снятия'])
                . Html::submitButton('Снять с продажи…', ['class' => 'btn btn-warning btn-sm']) . Html::endForm();
        }],
    ]]) ?>
</div></div>
<?php if ($context['can_publish']): ?>
<div class="box box-primary"><div class="box-body">
    <h2>Новое предложение</h2>
    <p>Навес уменьшает уличный износ; мастерская и дом защищают оборудование от него.
        Дом включает одну койку, занимающую единицу площади. Остальная площадь доступна под оборудование.</p>
    <?php $form = ActiveForm::begin(['action' => ['preview', 'id' => $id], 'method' => 'post']); ?>
        <?= Html::hiddenInput('operation', 'publish') ?>
        <?= $form->errorSummary($model) ?>
        <?= $form->field($model, 'name')->textInput(['maxlength' => 120]) ?>
        <?= $form->field($model, 'kind')->dropDownList($kinds) ?>
        <?= $form->field($model, 'delivery')->dropDownList(['ready' => 'Купить готовую', 'construction' => 'Построить по времени']) ?>
        <?= $form->field($model, 'duration_seconds')->input('number', ['min' => 0, 'max' => 604800])->hint('Для стройки 60–604800 секунд; для готовой постройки — 0.') ?>
        <?= $form->field($model, 'materials_json')->textarea(['rows' => 3])->hint('До 8 видов обычного добываемого сырья. Формат: [{"item_id": 1, "quantity": 10}]. ID берите из каталога крафта. Для готовой постройки: [].') ?>
        <p>При старте стройки Cr и материалы резервируются. При отмене до завершения Cr освобождаются в бюджете, все материалы возвращаются в рюкзак. Для отмены нужно место под весь возврат. При паузе резервы сохраняются.</p>
        <div class="row">
            <div class="col-md-4"><?= $form->field($model, 'area')->input('number', ['min' => 1, 'max' => 4]) ?></div>
            <div class="col-md-4"><?= $form->field($model, 'slots')->input('number', ['min' => 1, 'max' => 4]) ?></div>
            <div class="col-md-4"><?= $form->field($model, 'expansion_limit')->input('number', ['min' => 1, 'max' => 4]) ?></div>
        </div>
        <?= $form->field($model, 'price')->textInput(['inputmode' => 'decimal'])->hint('Положительная сумма, до четырёх знаков после точки.') ?>
        <?= $form->field($model, 'expansion_base_price')->textInput(['inputmode' => 'decimal'])->hint('Оставьте пустым, если расширений нет. Дополнительные места стоят B, 2B, 3B…') ?>
        <?= $form->field($model, 'reason')->textarea(['rows' => 2, 'maxlength' => 255]) ?>
        <?= Html::submitButton('Предварительный просмотр', ['class' => 'btn btn-primary']) ?>
    <?php ActiveForm::end(); ?>
</div></div>
<?php else: ?>
    <p class="alert alert-info">Публикация доступна владельцу поселения, а для системного поселения — администратору, когда действия в мире включены.</p>
<?php endif ?>
