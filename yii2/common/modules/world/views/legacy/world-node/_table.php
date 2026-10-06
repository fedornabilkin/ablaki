<?php
use common\modules\world\model\WorldNodeForm;
use mdm\admin\components\Helper;
use yii\grid\GridView;
use yii\helpers\Html;

echo GridView::widget(['dataProvider' => $provider, 'columns' => [
    ['attribute' => 'id', 'label' => 'ID'],
    ['attribute' => 'name', 'label' => 'Название', 'format' => 'raw', 'value' => static function (array $row): string {
        $route = '/' . WorldNodeForm::ROUTES[$row['node_type']] . '/view';
        return Helper::checkRoute($route) ? Html::a(Html::encode($row['name']), [$route, 'id' => $row['id']]) : Html::encode($row['name']);
    }],
    ['attribute' => 'code', 'label' => 'Код'],
    ['attribute' => 'node_type', 'label' => 'Раздел', 'value' => static function (array $row): string { return WorldNodeForm::TYPES[$row['node_type']]; }],
    ['attribute' => 'parent_id', 'label' => 'Родитель, ID'], ['attribute' => 'owner_user_id', 'label' => 'Владелец, ID'],
    ['attribute' => 'status', 'label' => 'Состояние', 'value' => static function (array $row): string { return $row['status'] === 'archived' ? 'В архиве' : 'Действует'; }],
    ['attribute' => 'revision', 'label' => 'Версия'],
    ['label' => 'Действия', 'format' => 'raw', 'value' => static function (array $row): string {
        return \common\modules\world\models\admin\WorldRelations::actions('world_node', $row);
    }],
]]);
