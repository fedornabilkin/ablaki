<?php
use yii\grid\GridView;
use yii\helpers\Html;

$this->title = 'Журнал изменений мира';
$this->params['breadcrumbs'][] = ['label' => 'Мир', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <p>Журнал административных изменений и операций с постройками. Записи о публикации и снятии предложений сохраняются с момента подключения редактора.</p>
    <?= Html::beginForm(['audit'], 'get', ['class' => 'form-inline']) ?>
        <?= Html::label('Причина', 'audit-q') ?>
        <?= Html::textInput('q', $q, ['id' => 'audit-q', 'class' => 'form-control', 'maxlength' => 120]) ?>
        <?= Html::label('Объект, ID', 'audit-node') ?>
        <?= Html::input('number', 'node_id', $node, ['id' => 'audit-node', 'min' => 1, 'class' => 'form-control']) ?>
        <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?>
    <?= Html::endForm() ?>
    <?= GridView::widget(['dataProvider' => $provider, 'columns' => [
        ['attribute' => 'id', 'label' => 'ID'],
        ['attribute' => 'created_at', 'label' => 'Время', 'format' => 'datetime'],
        ['attribute' => 'actor_user_id', 'label' => 'Автор, ID'],
        ['attribute' => 'node_id', 'label' => 'Объект', 'format' => 'raw', 'value' => static function (array $row): string {
            return Html::a('#' . (int)$row['node_id'], ['view', 'id' => $row['node_id']]);
        }],
        ['attribute' => 'action', 'label' => 'Действие'],
        ['attribute' => 'reason', 'label' => 'Причина'],
        ['label' => 'Условия', 'format' => 'raw', 'value' => static function (array $row): string {
            $result = '<details><summary>До / после</summary>';
            foreach (['before_json' => 'До', 'after_json' => 'После'] as $key => $label) {
                $data = json_decode($row[$key], true, 512, JSON_THROW_ON_ERROR);
                $result .= '<p>' . $label . '</p><pre>' . Html::encode(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '</pre>';
            }
            return $result . '<p>Операция: ' . Html::encode($row['operation_id']) . '</p></details>';
        }],
    ]]) ?>
</div></div>
