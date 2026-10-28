<?php
use common\modules\world\model\WorldNodeForm;
use yii\helpers\Html;

$this->title = 'Подтверждение изменения';
$this->params['breadcrumbs'][] = ['label' => WorldNodeForm::TYPES[$type], 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$terms = $record['quote']['terms']; $model = new WorldNodeForm($type);
$before = ($terms['before']['node'] ?? []) + ($terms['before']['details'] ?? []);
$after = ($terms['after']['node'] ?? []) + ($terms['after']['details'] ?? []);
if ($record['action'] === 'delete') $after = ['status' => 'archived'];
$display = static function ($value): string { return $value === null ? '—' : (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)); };
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::encode(['create' => 'Создание объекта', 'update' => 'Изменение объекта', 'delete' => 'Удаление объекта в архив'][$record['action']]) ?><?= $id ? ' №' . $id . ': ' . Html::encode($before['name']) : '' ?></p>
    <p>Причина: <?= Html::encode($record['input']['reason']) ?></p>
    <table class="table table-bordered"><thead><tr><th>Поле</th><th>Было</th><th>Будет</th></tr></thead><tbody>
    <?php foreach ($after as $field => $value): ?>
        <?php if ($record['action'] !== 'create' && array_key_exists($field, $before) && (string)$before[$field] === (string)$value) continue; ?>
        <tr><td><?= Html::encode(['node_type' => 'Тип', 'status' => 'Состояние', 'garden_node_id' => 'Огород, ID'][$field] ?? $model->getAttributeLabel($field)) ?></td>
            <td><?= Html::encode($display($before[$field] ?? null)) ?></td><td><?= Html::encode($display($value)) ?></td></tr>
    <?php endforeach ?>
    </tbody></table>
    <p>Подтверждение действует 5 минут. Если объект изменится, потребуется новый предварительный просмотр.</p>
    <?= Html::beginForm([$record['action'], 'id' => $id ?: null], 'post') ?>
        <?= Html::hiddenInput('quote_id', $record['quote']['quote_id']) ?>
        <?= Html::hiddenInput('digest', $record['digest']) ?>
        <?= Html::submitButton($record['action'] === 'delete' ? 'Подтвердить удаление' : 'Сохранить', ['class' => $record['action'] === 'delete' ? 'btn btn-danger' : 'btn btn-primary']) ?>
        <?= Html::a('Отмена', $id ? ['view', 'id' => $id] : ['index'], ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
</div></div>
