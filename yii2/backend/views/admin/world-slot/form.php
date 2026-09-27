<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
$this->title = ['create' => 'Создать место', 'update' => 'Изменить место', 'delete' => 'Удалить место'][$action];
$this->params['breadcrumbs'][] = ['label' => 'Места оборудования', 'url' => ['index', 'node_id' => $model->node_id]];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <p>Комната: <?= Html::encode($state['room']['node']['name']) ?>, №<?= (int)$model->node_id ?>. Площадь: <?= (int)$state['room']['details']['area'] ?>.</p>
    <p>Изменять можно пустое место. Защита должна совпадать с комнатой. Удаляется только последнее пустое место; оплаченные места сохраняются.</p>
    <?php $form = ActiveForm::begin(); ?>
        <?= $form->errorSummary($model) ?>
        <?= $this->render('_fields', compact('form', 'model')) ?>
        <?= Html::submitButton('Предварительный просмотр', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Отмена', ['index', 'node_id' => $model->node_id], ['class' => 'btn btn-default']) ?>
    <?php ActiveForm::end(); ?>
</div></div>
