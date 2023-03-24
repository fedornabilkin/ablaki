<?php

/* @var $this yii\web\View */
/* @var $model common\models\Todo */

$this->title = Yii::t('app', 'Update Todo: {name}', [
    'name' => $model->title,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Todos'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->title, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Update');
?>
<div class="todo-update">

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
