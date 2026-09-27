<?php
use yii\helpers\Html;
$this->title = 'Подтвердить изменение места';
$this->params['breadcrumbs'][] = ['label' => 'Места оборудования', 'url' => ['index', 'node_id' => $node]];
$this->params['breadcrumbs'][] = $this->title;
$terms = $record['quote']['terms'];
$labels = ['code' => 'Код', 'slot_type' => 'Назначение', 'size' => 'Площадь', 'exposure_class' => 'Защита', 'compatibility_json' => 'Ограничения предметов'];
?>
<div class="box box-primary"><div class="box-body">
    <p>Комната: <?= Html::encode($terms['room_name']) ?>. Место №<?= (int)$terms['position'] ?>.</p>
    <p>Действие: <?= Html::encode(['create' => 'Создать', 'update' => 'Изменить', 'delete' => 'Удалить'][$record['action']]) ?>. Причина: <?= Html::encode($record['input']['reason']) ?></p>
    <table class="table table-bordered"><thead><tr><th>Поле</th><th>Было</th><th>Будет</th></tr></thead><tbody>
        <?php foreach ($labels as $field => $label): ?>
            <tr><td><?= Html::encode($label) ?></td><td><?= Html::encode($terms['before'][$field] ?? '—') ?></td><td><?= Html::encode($terms['after'][$field] ?? '—') ?></td></tr>
        <?php endforeach ?>
    </tbody></table>
    <p>Вместимость после изменения: <?= (int)$terms['capacity'] ?>. Подтверждение действует 5 минут.</p>
    <?= Html::beginForm([$record['action'], 'node_id' => $node, 'position' => $position ?: null], 'post') ?>
        <?= Html::hiddenInput('quote_id', $record['quote']['quote_id']) ?>
        <?= Html::hiddenInput('digest', $record['digest']) ?>
        <?= Html::submitButton('Подтвердить', ['class' => $record['action'] === 'delete' ? 'btn btn-danger' : 'btn btn-primary']) ?>
        <?= Html::a('Отмена', ['index', 'node_id' => $node], ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
</div></div>
