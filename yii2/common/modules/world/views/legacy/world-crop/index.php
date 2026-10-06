<?php
use yii\helpers\Html;
$this->title = 'Культуры и выращивание';
?>
<div class="box box-primary"><div class="box-body">
    <?php if (\mdm\admin\components\Helper::checkRoute('/world-crop/create')): ?><p><?= Html::a('Добавить культуру', ['create'], ['class' => 'btn btn-success']) ?></p><?php endif ?>
    <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?>
    <?= Html::textInput('q', $q, ['class' => 'form-control', 'placeholder' => 'Название или код']) ?>
    <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?><?= Html::endForm() ?>
    <?= \yii\grid\GridView::widget(['dataProvider' => $provider, 'columns' => \common\modules\world\models\admin\WorldRelations::columns('world_crop')]) ?>
</div></div>
