<?php
use yii\helpers\Html;
use yii\widgets\DetailView;
$this->title = $model->name;
?>
<h1><?= Html::encode($this->title) ?></h1>
<p><?= Html::a('Список', ['index'], ['class' => 'btn btn-default']) ?>
<?= Html::a('Редактировать', ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
<?= Html::a('В архив / отключить', ['delete', 'id' => $model->id], ['class' => 'btn btn-warning', 'data' => ['method' => 'post', 'confirm' => 'Подтвердить изменение?']]) ?></p>
<?php
$attributes = array_values(array_filter(array_unique(array_merge($this->context->columns, $this->context->fields, $model->attributes(),
    $model instanceof \common\modules\world\models\Node ? ['position_x', 'position_y'] : [])), static function ($attribute) use ($model) { return $model->hasAttribute($attribute); }));
if ($model instanceof \common\modules\world\models\Node && $model->parent_id === null) {
    $attributes[] = ['label' => 'Секунд мира за реальную секунду', 'value' => \common\modules\world\models\WorldTime::rate($model->id)];
}
if ($model instanceof \common\modules\world\models\NodeTemplate) {
    $attributes = array_values(array_filter($attributes, static function ($attribute) { return $attribute !== 'build_seconds'; }));
    $attributes[] = ['label' => 'Трудоёмкость (время мира)', 'value' => \common\modules\world\models\WorldTime::duration($model->worldWorkSeconds())];
}
if ($model instanceof \common\modules\world\models\NodeTemplate) $attributes[] = ['label' => 'Разрешённые родители', 'format' => 'raw',
    'value' => implode('<br>', array_map(static function ($parent) { return \backend\support\EntityDisplay::link('world_template', $parent->getAttributes()); }, $model->getAllowedParents()->all())) ?: '—'];
?>
<?= DetailView::widget(['model' => $model, 'attributes' => $attributes]) ?>
<?php if ($model instanceof \common\modules\world\models\Node): ?>
<h2>Родитель</h2>
<p><?= $model->parent ? \backend\support\EntityDisplay::link('world_node', $model->parent->getAttributes()) : 'Нет: корень мира' ?></p>
<h2>Дочерние объекты</h2>
<?= \yii\grid\GridView::widget(['dataProvider' => new \yii\data\ActiveDataProvider([
    'query' => $model->getChildren()->andWhere(['<>', 'status', 'archived']), 'pagination' => ['pageSize' => 20],
    'sort' => ['defaultOrder' => ['id' => SORT_ASC]],
]), 'columns' => ['name', 'node_type', 'owner_user_id', 'status', 'position_x', 'position_y']]) ?>
<?php endif ?>
