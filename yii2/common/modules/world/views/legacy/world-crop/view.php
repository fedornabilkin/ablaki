<?php
use yii\helpers\Html;
$this->title = $row['name'];
$this->params['breadcrumbs'][] = ['label' => 'Культуры', 'url' => ['index']];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= \common\modules\world\models\admin\WorldRelations::actions('world_crop', ['id' => $row['crop_id']] + $row) ?></p>
    <?= \yii\widgets\DetailView::widget(['model' => $row, 'attributes' => ['code', 'name', 'version', 'status', 'seed_quantity', 'yield_quantity', 'grow_seconds', 'water_interval_seconds', 'water_window_seconds', 'harvest_window_seconds']]) ?>
</div></div>
<?= $this->render('@common/modules/world/views/legacy/world-record/_relations', ['table' => 'world_crop', 'row' => ['id' => $row['crop_id']] + $row]) ?>
