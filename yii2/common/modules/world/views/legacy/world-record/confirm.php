<?php
use common\modules\world\admin\WorldEntityCatalog;
use yii\helpers\Html;
$this->title = 'Подтвердить изменение';
$this->params['breadcrumbs'][] = ['label' => $definition['label'], 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$terms = $record['quote']['terms'];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= $record['action'] === 'withdraw' ? 'Снятие предложения с публикации.' : ($id ? 'Замена предложения новыми условиями.' : 'Публикация предложения.') ?></p>
    <p>Причина: <?= Html::encode($record['input']['admin_reason']) ?></p>
    <table class="table table-bordered"><tbody>
    <?php foreach (($terms['config'] ?? $record['input']) as $field => $value): ?>
        <?php if (in_array($field, ['admin_reason', 'expected_offer_id', 'replaces_offer_id'], true)) continue; ?>
        <tr><th><?= Html::encode(WorldEntityCatalog::label($field)) ?></th><td><?= Html::encode(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($value === null ? '—' : (string)$value)) ?></td></tr>
    <?php endforeach ?>
    </tbody></table>
    <p>Подтверждение действует 5 минут. Если условия изменятся, потребуется новый предварительный просмотр.</p>
    <?= Html::beginForm([$this->context->action->id, 'id' => $id ?: null], 'post') ?>
        <?= Html::hiddenInput('quote_id', $record['quote']['quote_id']) ?>
        <?= Html::hiddenInput('digest', $record['digest']) ?>
        <?= Html::submitButton($record['action'] === 'withdraw' ? 'Снять с публикации' : 'Сохранить', ['class' => $record['action'] === 'withdraw' ? 'btn btn-danger' : 'btn btn-primary']) ?>
        <?= Html::a('Отмена', $id ? ['view', 'id' => $id] : ['index'], ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
</div></div>
