<?php
use yii\helpers\Html;

$this->title = $node['name'];
$this->params['breadcrumbs'][] = ['label' => 'Мир', 'url' => ['index']];
foreach ($navigation['breadcrumbs'] as $crumb) {
    if ($crumb['id'] !== $node['id']) $this->params['breadcrumbs'][] = ['label' => $crumb['name'], 'url' => ['view', 'id' => $crumb['id']]];
}
$this->params['breadcrumbs'][] = $this->title;
$labels = ['area' => 'Площадь', 'level' => 'Уровень', 'condition' => 'Прочность', 'max_condition' => 'Максимальная прочность',
    'operational_status' => 'Состояние постройки', 'exposure_class' => 'Защита оборудования', 'template_revision_id' => 'Версия шаблона',
    'plot_kind' => 'Вид участка', 'allow_building' => 'Строительство разрешено', 'settlement_kind' => 'Вид поселения',
    'ordinal' => 'Номер грядки', 'unlocked' => 'Доступна', 'fertility' => 'Плодородие', 'population' => 'Население'];
?>
<div class="box box-primary"><div class="box-body">
    <?php $crud = '/' . \common\modules\world\model\WorldNodeForm::ROUTES[$node['type']] . '/view'; ?>
    <?php if (\mdm\admin\components\Helper::checkRoute($crud)): ?>
        <p><?= Html::a('Карточка и редактирование', [$crud, 'id' => $node['id']], ['class' => 'btn btn-primary']) ?></p>
    <?php endif ?>
    <p>Объект №<?= (int)$node['id'] ?>. Версия <?= (int)$node['revision'] ?>. Состояние: <?= Html::encode($node['status']) ?>.</p>
    <p><?= Html::a('Журнал изменений объекта', ['audit', 'node_id' => $node['id']], ['class' => 'btn btn-default']) ?></p>
    <dl class="dl-horizontal">
    <?php foreach ((array)$node['details'] as $key => $value): ?>
        <dt><?= Html::encode($labels[$key] ?? $key) ?></dt>
        <dd><?= Html::encode(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?></dd>
    <?php endforeach ?>
    </dl>
    <?php if ($node['type'] === 'SETTLEMENT' && $node['status'] === 'active' && $node['visibility'] === 'public'): ?>
        <p><?= Html::a('Каталог построек', ['premises', 'id' => $node['id']], ['class' => 'btn btn-primary']) ?></p>
    <?php endif ?>
    <h2>Состав объекта</h2>
    <?= $this->render('_nodes', ['provider' => $children]) ?>
</div></div>
