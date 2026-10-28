<?php
use common\modules\world\models\admin\WorldRelations;
use yii\grid\GridView;
use yii\helpers\Html;
$panels = WorldRelations::related($table, $row);
?>
<?php if ($panels): ?><h2>Связанные записи</h2><?php endif ?>
<?php foreach ($panels as $panel): ?>
<section class="box box-default" id="relation-<?= Html::encode($panel['child']) ?>">
    <div class="box-header with-border"><h3 class="box-title"><?= Html::encode($panel['definition']['label']) ?> <small><?= (int)$panel['provider']->totalCount ?></small></h3></div>
    <div class="box-body">
        <?php if ($panel['create']): ?><p><?= Html::a('Добавить', $panel['create'], ['class' => 'btn btn-success btn-sm']) ?></p><?php endif ?>
        <div class="table-responsive"><?= GridView::widget(['id' => 'grid-' . $panel['child'], 'dataProvider' => $panel['provider'], 'columns' => WorldRelations::columns($panel['child'])]) ?></div>
    </div>
</section>
<?php endforeach ?>
