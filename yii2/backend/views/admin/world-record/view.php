<?php
use backend\components\WorldEntityCatalog;
use backend\components\WorldRelations;
use yii\helpers\Html;
use yii\widgets\DetailView;
$pk = array_intersect_key($row, array_flip(WorldRelations::schema($table)->primaryKey));
$this->title = ($row['name'] ?? $definition['label']) . ' · ' . implode(' / ', $pk);
$this->params['breadcrumbs'][] = ['label' => $definition['label'], 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$attributes = [];
foreach ($row as $field => $value) $attributes[] = ['label' => WorldEntityCatalog::label($field), 'format' => 'raw', 'value' => WorldRelations::value($table, $field, $value, $row)];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::a('К списку', ['index'], ['class' => 'btn btn-default']) ?> <?= WorldRelations::actions($table, $row) ?></p>
    <?php if (!WorldRelations::editable($table, $row)): ?><p class="text-muted">Эта запись хранит историю или условия игровой операции. Доступные изменения выполняются через связанные объекты ниже.</p><?php endif ?>
    <?= DetailView::widget(['model' => $row, 'attributes' => $attributes]) ?>
</div></div>
<?= $this->render('_relations', compact('table', 'row')) ?>
