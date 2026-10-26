<?php
use yii\helpers\Html;
use yii\grid\GridView;
use yii\widgets\ActiveForm;
$this->title = $this->context->title;
?>
<h1><?= Html::encode($this->title) ?></h1>
<?php if (!empty($financialOverview)): ?><?= $this->render('_world-finances', ['financialOverview' => $financialOverview]) ?><?php endif ?>
<p><?= Html::a('Создать', ['create'], ['class' => 'btn btn-success']) ?></p>
<?php $form = ActiveForm::begin(['method' => 'get', 'action' => ['index']]); ?>
<?= $form->field($searchModel, 'q')->textInput(['placeholder' => 'Название'])->label('Поиск') ?>
<?= Html::submitButton('Найти', ['class' => 'btn btn-primary']) ?>
<?= Html::a('Сбросить', ['index'], ['class' => 'btn btn-default']) ?>
<?php ActiveForm::end(); ?>
<?php $columns = array_map(static function ($column) {
    return $column === 'build_seconds' ? ['attribute'=>'build_seconds', 'label'=>'Трудоёмкость (время мира)', 'value'=>static function ($model) { return \common\modules\world\models\WorldTime::duration($model->worldWorkSeconds()); }] : $column;
}, $this->context->columns); ?>
<?= GridView::widget(['dataProvider' => $dataProvider, 'filterModel' => $searchModel,
    'columns' => array_merge($columns, [['class' => 'yii\grid\ActionColumn', 'template' => '{view} {update}']])]) ?>
