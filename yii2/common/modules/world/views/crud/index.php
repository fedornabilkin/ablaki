<?php
use yii\helpers\Html;
use yii\grid\GridView;
use yii\widgets\ActiveForm;
$this->title = $this->context->title;
?>
<h1><?= Html::encode($this->title) ?></h1>
<p><?= Html::a('Создать', ['create'], ['class' => 'btn btn-success']) ?></p>
<?php $form = ActiveForm::begin(['method' => 'get', 'action' => ['index']]); ?>
<?= $form->field($searchModel, 'q')->textInput(['placeholder' => 'Название'])->label('Поиск') ?>
<?= Html::submitButton('Найти', ['class' => 'btn btn-primary']) ?>
<?= Html::a('Сбросить', ['index'], ['class' => 'btn btn-default']) ?>
<?php ActiveForm::end(); ?>
<?= GridView::widget(['dataProvider' => $dataProvider, 'filterModel' => $searchModel,
    'columns' => array_merge($this->context->columns, [['class' => 'yii\grid\ActionColumn', 'template' => '{view} {update}']])]) ?>
