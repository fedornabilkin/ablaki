<?php
use mdm\admin\components\Helper;
use yii\grid\GridView;
use yii\helpers\Html;
$this->title = 'Места оборудования'; $this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <?php if (Helper::checkRoute('/world-slot/create')): ?><p><?= Html::a('Создать', ['create', 'node_id' => $node ?: null], ['class' => 'btn btn-success']) ?></p><?php endif ?>
    <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?>
        <?= Html::label('Код места или название комнаты', 'slot-q') ?>
        <?= Html::textInput('q', $q, ['id' => 'slot-q', 'class' => 'form-control', 'maxlength' => 120]) ?>
        <?= Html::label('Объект, ID', 'slot-node') ?>
        <?= Html::input('number', 'node_id', $node, ['id' => 'slot-node', 'class' => 'form-control', 'min' => 1]) ?>
        <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?>
        <?= Html::a('Сбросить', ['index'], ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
    <?= GridView::widget(['dataProvider' => $provider, 'columns' => [
        ['attribute' => 'storage_id', 'label' => 'Хранилище, ID'], ['attribute' => 'position', 'label' => 'Номер места'],
        ['attribute' => 'room_name', 'label' => 'Объект'], ['attribute' => 'node_id', 'label' => 'Объект, ID'],
        ['attribute' => 'code', 'label' => 'Код'], ['attribute' => 'slot_type', 'label' => 'Назначение'],
        ['attribute' => 'exposure_class', 'label' => 'Защита'], ['attribute' => 'status', 'label' => 'Состояние'],
        ['label' => 'Действия', 'format' => 'raw', 'value' => static function (array $row): string {
            return \common\modules\world\models\admin\WorldRelations::actions('world_slot', $row);
        }],
    ]]) ?>
</div></div>
