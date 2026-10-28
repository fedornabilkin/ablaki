<?php
use common\modules\world\model\WorldNodeForm;
use mdm\admin\components\Helper;
use yii\helpers\Html;

$this->title = WorldNodeForm::TYPES[$type];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <?php if (Helper::checkRoute('/' . WorldNodeForm::ROUTES[$type] . '/create')): ?>
        <p><?= Html::a('Создать', ['create'], ['class' => 'btn btn-success']) ?></p>
    <?php endif ?>
    <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?>
        <?= Html::label('Название или код', 'node-q') ?>
        <?= Html::textInput('q', $q, ['id' => 'node-q', 'class' => 'form-control', 'maxlength' => 120]) ?>
        <?= Html::label('Состояние', 'node-status') ?>
        <?= Html::dropDownList('status', $status, ['active' => 'Действующие', 'archived' => 'В архиве', '' => 'Все'], ['id' => 'node-status', 'class' => 'form-control']) ?>
        <?= Html::label('Родитель, ID', 'node-parent') ?>
        <?= Html::input('number', 'parent_id', $parent, ['id' => 'node-parent', 'class' => 'form-control', 'min' => 1]) ?>
        <?= Html::label('Владелец, ID', 'node-owner') ?>
        <?= Html::input('number', 'owner_user_id', $owner, ['id' => 'node-owner', 'class' => 'form-control', 'min' => 1]) ?>
        <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?>
        <?= Html::a('Сбросить', ['index'], ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
    <?= $this->render('_table', ['provider' => $provider]) ?>
</div></div>
