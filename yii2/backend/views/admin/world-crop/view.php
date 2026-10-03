<?php
use yii\helpers\Html;
$this->title = $row['name'];
$this->params['breadcrumbs'][] = ['label' => 'Культуры', 'url' => ['index']];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::a('Новая редакция', ['update', 'id' => $row['crop_id']], ['class' => 'btn btn-primary']) ?>
    <?php if ($row['status'] === 'published'): ?><?= Html::a('Снять с публикации', ['delete', 'id' => $row['crop_id']], ['class' => 'btn btn-danger', 'data-method' => 'post', 'data-confirm' => 'Новые посадки будут закрыты. Текущие посевы сохранятся. Продолжить?']) ?><?php endif ?></p>
    <?= \yii\widgets\DetailView::widget(['model' => $row, 'attributes' => ['code', 'name', 'version', 'status', 'seed_quantity', 'yield_quantity', 'grow_seconds', 'water_interval_seconds', 'water_window_seconds', 'harvest_window_seconds']]) ?>
</div></div>
