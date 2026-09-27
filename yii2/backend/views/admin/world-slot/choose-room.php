<?php
use yii\helpers\Html;
$this->title = 'Создать место оборудования';
$this->params['breadcrumbs'][] = ['label' => 'Места оборудования', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <p>Укажите комнату. Новое место займёт часть её площади. Места купленных помещений расширяются через игровую покупку.</p>
    <?= Html::beginForm(['create'], 'get') ?>
        <?= Html::label('Комната, ID', 'slot-room') ?>
        <?= Html::input('number', 'node_id', '', ['id' => 'slot-room', 'required' => true, 'min' => 1, 'class' => 'form-control']) ?>
        <?= Html::submitButton('Продолжить', ['class' => 'btn btn-primary']) ?>
    <?= Html::endForm() ?>
</div></div>
