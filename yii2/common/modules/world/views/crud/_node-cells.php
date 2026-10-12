<?php
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
?>
<fieldset class="admin-wide admin-node-cells" data-cells-url="<?= Html::encode(Url::to(['/entity-lookup/cells'])) ?>" data-except="<?= Html::encode((string)$model->id) ?>" data-selected="<?= Html::encode($model->cellChoice ?: $model->position_x . ':' . $model->position_y) ?>">
    <legend>Координаты в родительском объекте</legend>
    <p class="text-muted">Выберите свободную ячейку. Занятые ячейки недоступны; текущая ячейка объекта остаётся доступной.</p>
    <?= $form->field($model, 'cellChoice')->hiddenInput()->label(false) ?>
    <div class="admin-map-cells" aria-live="polite"></div>
    <p class="admin-cells-status"></p>
    <button type="button" class="btn btn-default admin-cells-more" hidden>Ещё ячейки</button>
</fieldset>
