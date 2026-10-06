<?php
use common\modules\world\model\WorldNodeForm;
use mdm\admin\components\Helper;
use yii\helpers\Html;
use yii\widgets\DetailView;

$node = $snapshot['node']; $id = (int)$node['id']; $route = '/' . WorldNodeForm::ROUTES[$model->type()];
$this->title = $node['name'];
$this->params['breadcrumbs'][] = ['label' => WorldNodeForm::TYPES[$model->type()], 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$attributes = [['label' => 'ID', 'value' => $id], ['label' => 'Состояние', 'value' => $node['status'] === 'archived' ? 'В архиве' : 'Действует'], ['label' => 'Версия', 'value' => $node['revision']]];
foreach (array_merge(['name', 'code', 'slug', 'parent_id', 'owner_user_id', 'visibility', 'portable', 'position_x', 'position_y', 'position'], $model->detailFields()) as $field) {
    $value = $model->$field;
    $attributes[] = ['label' => $model->getAttributeLabel($field), 'format' => 'raw', 'value' => $field === 'parent_id'
        ? \common\modules\world\models\admin\WorldRelations::value('world_node', $field, $value)
        : Html::encode((string)(WorldNodeForm::choices()[$field][$value] ?? $value))];
}
$attributes[] = ['label' => 'Полигон на карте', 'value' => $model->footprint ?: 'Одна ячейка'];
?>
<div class="box box-primary"><div class="box-body">
    <p>
        <?php if ($node['status'] === 'active' && Helper::checkRoute($route . '/update')): ?>
            <?= Html::a('Изменить', ['update', 'id' => $id], ['class' => 'btn btn-primary']) ?>
        <?php endif ?>
        <?php if (Helper::checkRoute('/world-admin/audit')): ?>
            <?= Html::a('Журнал изменений', ['/world-admin/audit', 'node_id' => $id], ['class' => 'btn btn-default']) ?>
        <?php endif ?>
        <?php if ($model->type() === 'SETTLEMENT' && $node['status'] === 'active' && $node['visibility'] === 'public' && Helper::checkRoute('/world-admin/premises')): ?>
            <?= Html::a('Каталог построек', ['/world-admin/premises', 'id' => $id], ['class' => 'btn btn-primary']) ?>
        <?php endif ?>
        <?php if ($model->type() === 'ROOM' && Helper::checkRoute('/world-slot/index')): ?>
            <?= Html::a('Места оборудования', ['/world-slot/index', 'node_id' => $id], ['class' => 'btn btn-default']) ?>
        <?php endif ?>
    </p>
    <?= DetailView::widget(['model' => $node, 'attributes' => $attributes]) ?>
    <?php if ($usage): ?><p class="alert alert-info">Есть игровые связи. Имущество, оплаченные права и финансовая история сохраняются; прямое изменение связанных игровых параметров и удаление заблокированы.</p><?php endif ?>
    <h2>Дочерние объекты</h2>
    <p>
        <?php foreach (['WORLD' => ['REGION'], 'REGION' => ['SETTLEMENT'], 'SETTLEMENT' => ['BUILDING', 'PLOT'], 'BUILDING' => ['ROOM', 'PLOT'], 'PLOT' => $model->plot_kind === 'garden' ? ['BED'] : ($model->plot_kind === 'campsite' ? ['BUILDING', 'PLOT'] : ['BUILDING']), 'ROOM' => [], 'BED' => []][$model->type()] as $child): ?>
            <?php if ($node['status'] === 'active' && Helper::checkRoute('/' . WorldNodeForm::ROUTES[$child] . '/create')): ?>
                <?= Html::a('Создать · ' . WorldNodeForm::TYPES[$child], ['/' . WorldNodeForm::ROUTES[$child] . '/create', 'parent_id' => $id], ['class' => 'btn btn-default']) ?>
            <?php endif ?>
        <?php endforeach ?>
    </p>
    <?= $this->render('_table', ['provider' => $children]) ?>
    <?php if ($node['status'] === 'active' && Helper::checkRoute($route . '/delete')): ?>
        <hr>
        <p>Удаление перемещает объект в архив и сохраняет журнал. Объект с действующими дочерними объектами или игровыми связями удалить нельзя.</p>
        <?= Html::beginForm(['delete', 'id' => $id], 'post') ?>
            <?= Html::hiddenInput('revision', $node['revision']) ?>
            <?= Html::label('Причина удаления', 'delete-reason') ?>
            <?= Html::textInput('reason', '', ['id' => 'delete-reason', 'required' => true, 'maxlength' => 255, 'class' => 'form-control']) ?>
            <?= Html::submitButton('Удалить…', ['class' => 'btn btn-danger']) ?>
        <?= Html::endForm() ?>
    <?php endif ?>
</div></div>
<?= $this->render('@common/modules/world/views/legacy/world-record/_relations', ['table' => 'world_node', 'row' => $node]) ?>
