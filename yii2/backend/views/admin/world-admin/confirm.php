<?php
use yii\helpers\Html;

$publish = $record['action'] === 'publish'; $quote = $record['quote']; $terms = $quote['terms'];
$this->title = $publish ? 'Подтверждение публикации' : 'Подтверждение снятия с продажи';
$this->params['breadcrumbs'][] = ['label' => 'Мир', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => 'Каталог построек', 'url' => ['premises', 'id' => $record['input']['node_id']]];
$this->params['breadcrumbs'][] = $this->title;
$labels = ['name' => 'Название', 'kind' => 'Тип', 'area' => 'Площадь', 'slots' => 'Мест оборудования при покупке',
    'price' => 'Цена, Cr', 'lodging_places' => 'Мест ночлега', 'expansion_limit' => 'Предел мест оборудования',
    'expansion_base_price' => 'Базовая цена расширения, Cr', 'exposure_class' => 'Защита', 'delivery' => 'Способ получения'];
$values = ['canopy' => 'Навес', 'workroom' => 'Мастерская', 'house' => 'Дом', 'covered' => 'Под навесом', 'indoor' => 'В помещении', 'ready' => 'Готовая постройка', 'construction' => 'Строительство по времени'];
?>
<div class="box box-warning"><div class="box-body">
    <p>Поселение №<?= (int)$record['input']['node_id'] ?>. Подтверждение действительно до <?= Html::encode(Yii::$app->formatter->asDatetime($quote['expires_at'])) ?>.</p>
    <?php if ($publish): ?>
        <dl class="dl-horizontal">
        <?php foreach ($labels as $key => $label): $value = $terms['config'][$key] ?? null; ?>
            <dt><?= Html::encode($label) ?></dt><dd><?= Html::encode($value === null ? 'Нет' : ($values[(string)$value] ?? (string)$value)) ?></dd>
        <?php endforeach ?>
        </dl>
        <p>Требования к покупателю:</p>
        <pre><?= Html::encode(json_encode($terms['config']['requirements'] ?? ['all' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php if (($terms['config']['delivery'] ?? 'ready') === 'construction'): ?>
            <p>Срок: <?= (int)$terms['config']['duration_seconds'] ?> секунд. До завершения Cr резервируются, материалы хранятся отдельно.
                Отмена освобождает весь резерв Cr и возвращает материалы в рюкзак; для возврата нужно свободное место. Пауза сохраняет резервы.</p>
            <ul><?php foreach ($terms['config']['materials'] as $material): ?>
                <li><?= Html::encode($material['name']) ?>: <?= (int)$material['quantity'] ?></li>
            <?php endforeach ?></ul>
        <?php endif ?>
        <p>Предложение появится в продаже. Покупка списывает указанную цену из бюджета участка в казну поселения.
            Ранее купленные постройки и действующие предложения сохранят свои условия.</p>
    <?php else: ?>
        <p>Снять предложение «<?= Html::encode($terms['name']) ?>» №<?= (int)$terms['offer_id'] ?>
            (версия условий №<?= (int)$terms['template_revision_id'] ?>).</p>
        <p>Новые покупки станут недоступны. Уже приобретённые постройки, имущество и опубликованная версия условий сохранятся.</p>
    <?php endif ?>
    <p>Причина: <?= Html::encode($record['input']['admin_reason']) ?></p>
    <details><summary>Версия подтверждаемых условий</summary>
        <p style="overflow-wrap:anywhere"><?= Html::encode($record['digest']) ?></p>
        <?php foreach ((array)$quote['expected_revisions'] as $target => $revision): ?>
            <p><?= Html::encode($target) ?>: <?= (int)$revision ?></p>
        <?php endforeach ?>
    </details>
    <?= Html::beginForm(['execute'], 'post') ?>
        <?= Html::hiddenInput('quote_id', $quote['quote_id']) ?>
        <?= Html::hiddenInput('digest', $record['digest']) ?>
        <?= Html::submitButton($publish ? 'Опубликовать' : 'Снять с продажи', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Вернуться без изменений', ['premises', 'id' => $record['input']['node_id']], ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
</div></div>
