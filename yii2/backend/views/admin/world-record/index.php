<?php
use backend\components\WorldEntityCatalog;
use backend\components\WorldRelations;
use mdm\admin\components\Helper;
use yii\grid\GridView;
use yii\helpers\Html;
$this->title = $definition['label'];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <?php if ($canCreate && Helper::checkRoute('/' . $definition['route'] . '/create')): ?>
        <p><?= Html::a('Создать', ['create'], ['class' => 'btn btn-success']) ?></p>
    <?php endif ?>
    <?= Html::beginForm(['index'], 'get') ?>
        <div class="form-group">
            <?= Html::label('Поиск по названию, коду, состоянию или ID', 'record-q') ?>
            <?= Html::textInput('q', $q, ['id' => 'record-q', 'maxlength' => 120, 'class' => 'form-control']) ?>
        </div>
        <details <?= array_filter($filters, static function ($value) { return $value !== ''; }) ? 'open' : '' ?>><summary>Фильтры по связям и состоянию</summary><div class="row">
        <?php foreach ($fields as $field): ?>
            <div class="form-group col-md-3">
                <?= Html::label(WorldEntityCatalog::label($field), 'filter-' . $field) ?>
                <?= Html::textInput('filter[' . $field . ']', $filters[$field] ?? '', ['id' => 'filter-' . $field, 'maxlength' => 120, 'class' => 'form-control']) ?>
            </div>
        <?php endforeach ?>
        </div></details>
        <p><?= Html::submitButton('Найти', ['class' => 'btn btn-primary']) ?> <?= Html::a('Сбросить', ['index'], ['class' => 'btn btn-default']) ?></p>
    <?= Html::endForm() ?>
    <div class="table-responsive"><?= GridView::widget(['id' => 'records-' . $definition['route'], 'dataProvider' => $provider, 'columns' => WorldRelations::columns($table)]) ?></div>
</div></div>
