<?php
use mdm\admin\components\Helper;
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use yii\widgets\DetailView;
$slot = $state['slots'][$position]; $node = (int)$model->node_id;
$this->title = 'Место ' . $slot['code'];
$this->params['breadcrumbs'][] = ['label' => 'Места оборудования', 'url' => ['index', 'node_id' => $node]];
$this->params['breadcrumbs'][] = $this->title;
$attributes = [];
foreach (['node_id', 'code', 'slot_type', 'size', 'exposure_class', 'item_codes'] as $field) $attributes[] = ['label' => $model->getAttributeLabel($field), 'value' => $model->$field];
$attributes[] = ['label' => 'Состояние', 'value' => $slot['status']];
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::encode($state['room']['node']['name']) ?> · место №<?= (int)$position ?></p>
    <?php if ($state['room']['node']['node_type'] === 'ROOM' && Helper::checkRoute('/world-slot/update')): ?><p><?= Html::a('Изменить', ['update', 'node_id' => $node, 'position' => $position], ['class' => 'btn btn-primary']) ?></p><?php endif ?>
    <?= DetailView::widget(['model' => $slot, 'attributes' => $attributes]) ?>
    <?php if ($state['room']['node']['node_type'] === 'ROOM' && Helper::checkRoute('/world-slot/delete')): ?>
        <?php $form = ActiveForm::begin(['action' => ['delete', 'node_id' => $node, 'position' => $position], 'method' => 'post']); ?>
            <?php foreach (['node_id', 'revision', 'storage_revision', 'code', 'slot_type', 'size', 'exposure_class', 'item_codes'] as $field) echo $form->field($model, $field)->hiddenInput()->label(false); ?>
            <?= $form->field($model, 'reason')->textarea(['rows' => 2, 'maxlength' => 255]) ?>
            <?= Html::submitButton('Удалить…', ['class' => 'btn btn-danger']) ?>
        <?php ActiveForm::end(); ?>
    <?php endif ?>
</div></div>
