<?php
use yii\helpers\Html;
use yii\widgets\DetailView;
$this->title = $model->name;
?>
<h1><?= Html::encode($this->title) ?></h1>
<p><?= Html::a('Список', ['index'], ['class' => 'btn btn-default']) ?>
<?= Html::a('Редактировать', ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?></p>
<?= DetailView::widget(['model' => $model, 'attributes' => array_keys($model->attributes)]) ?>
