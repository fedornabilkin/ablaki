<?php
use yii\helpers\Html;
$this->title = 'Склад: ' . $row['name'];
$this->params['breadcrumbs'][] = ['label' => 'Склады', 'url' => ['index']];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::a('Настроить расширение', ['update', 'id' => $row['id']], ['class' => 'btn btn-primary']) ?> <?= Html::a('Постройка', ['/world-building/view', 'id' => $row['node_id']], ['class' => 'btn btn-default']) ?></p>
    <p>Доступно <?= (int)$row['capacity'] ?> ячеек из <?= (int)$row['max_capacity'] ?>. Базовая цена расширения: <?= Html::encode($row['base_price']) ?> Cr.</p>
    <p>Удаление склада выполняется вместе с постройкой. Предварительно нужно забрать вещи.</p>
    <?= \yii\grid\GridView::widget(['dataProvider' => $provider, 'columns' => [['attribute' => 'slot', 'label' => 'Ячейка'], ['attribute' => 'name', 'label' => 'Предмет'], ['attribute' => 'item_quantity', 'label' => 'Количество']]]) ?>
</div></div>
