<?php
use yii\grid\GridView;
use yii\helpers\Html;

echo GridView::widget(['dataProvider' => $provider, 'columns' => [
    ['attribute' => 'id', 'label' => 'ID'],
    ['attribute' => 'name', 'label' => 'Название', 'format' => 'raw', 'value' => static function (array $row): string {
        return Html::a(Html::encode($row['name']), ['view', 'id' => $row['id']]);
    }],
    ['attribute' => 'node_type', 'label' => 'Тип', 'value' => static function (array $row): string {
        return ['WORLD' => 'Мир', 'REGION' => 'Регион', 'SETTLEMENT' => 'Поселение', 'BUILDING' => 'Постройка',
            'ROOM' => 'Комната', 'PLOT' => 'Участок', 'BED' => 'Грядка'][$row['node_type']] ?? $row['node_type'];
    }],
    ['attribute' => 'parent_id', 'label' => 'Родитель, ID'],
    ['attribute' => 'owner_user_id', 'label' => 'Владелец, ID'],
    ['attribute' => 'status', 'label' => 'Состояние'],
    ['attribute' => 'revision', 'label' => 'Версия'],
]]);
