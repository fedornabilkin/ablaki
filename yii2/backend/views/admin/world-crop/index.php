<?php
use yii\helpers\Html;
$this->title = 'Культуры и выращивание';
?>
<div class="box box-primary"><div class="box-body">
    <p><?= Html::a('Добавить культуру', ['create'], ['class' => 'btn btn-success']) ?></p>
    <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?>
    <?= Html::textInput('q', $q, ['class' => 'form-control', 'placeholder' => 'Название или код']) ?>
    <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?><?= Html::endForm() ?>
    <?= \yii\grid\GridView::widget(['dataProvider' => $provider, 'columns' => ['id', 'code', 'name', ['class' => \yii\grid\ActionColumn::class, 'template' => '{view} {update}']]]) ?>
</div></div>
