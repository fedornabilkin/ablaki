<?php
use yii\helpers\Html;
$this->title = 'Склады';
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::a('Создать постройку со складом', ['/world-building/create'], ['class' => 'btn btn-success']) ?> · Выберите тип «Кузница», «Мастерская» или «Большой склад» и владельца.</p>
    <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?><?= Html::textInput('q', $q, ['class' => 'form-control', 'placeholder' => 'Название здания']) ?> <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?><?= Html::endForm() ?>
    <?= \yii\grid\GridView::widget(['dataProvider' => $provider, 'columns' => ['id', 'name', ['attribute' => 'owner_user_id', 'label' => 'Владелец'], ['attribute' => 'capacity', 'label' => 'Открыто ячеек'], ['attribute' => 'max_capacity', 'label' => 'Предел'], ['attribute' => 'base_price', 'label' => 'Базовая цена, Cr'], ['class' => \yii\grid\ActionColumn::class, 'template' => '{view} {update}']]]) ?>
</div></div>
