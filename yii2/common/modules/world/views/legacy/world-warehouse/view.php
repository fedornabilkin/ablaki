<?php
use yii\helpers\Html;
$this->title = 'Склад: ' . $row['name'];
$this->params['breadcrumbs'][] = ['label' => 'Склады', 'url' => ['index']];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= \common\modules\world\models\admin\WorldRelations::link(['/world-warehouse/update', 'id' => $row['id']], 'Настроить расширение', ['class' => 'btn btn-primary']) ?> <?= \common\modules\world\models\admin\WorldRelations::link(['/world-building/view', 'id' => $row['node_id']], 'Постройка', ['class' => 'btn btn-default']) ?></p>
    <p>Доступно <?= (int)$row['capacity'] ?> ячеек из <?= (int)$row['max_capacity'] ?>. Базовая цена расширения: <?= Html::encode($row['base_price']) ?> Cr.</p>
    <p>Удаление склада выполняется вместе с постройкой. Предварительно нужно забрать вещи.</p>
</div></div>
<?= $this->render('@common/modules/world/views/legacy/world-record/_relations', ['table' => 'craft_storage', 'row' => $row]) ?>
