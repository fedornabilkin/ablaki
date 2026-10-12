<?php
use common\modules\world\models\NodeTemplate;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\Json;
$templates = NodeTemplate::find()->select(['id', 'name', 'hierarchy_level', 'kind'])->where(['enabled' => 1])->andWhere(['not', ['hierarchy_level' => null]])->orderBy(['hierarchy_level' => SORT_ASC, 'name' => SORT_ASC])->asArray()->all();
$edges = (new Query())->from('world_template_parent')->all();
$parents = []; foreach ($edges as $edge) if (!isset($parents[$edge['template_id']])) $parents[$edge['template_id']] = $edge['parent_template_id'];
$branches = []; $known = array_column($templates, null, 'id');
foreach ($templates as $template) {
    $parent = $parents[$template['id']] ?? 0;
    if (!isset($known[$parent]) || (int)$known[$parent]['hierarchy_level'] >= (int)$template['hierarchy_level']) $parent = 0;
    $branches[$parent][] = $template;
}
$renderBranch = function ($parent) use (&$renderBranch, $branches, $model) {
    foreach ($branches[$parent] ?? [] as $template) {
        $id = (int)$template['id']; $hasChildren = !empty($branches[$id]);
        echo $hasChildren ? '<details open><summary>' : '<div class="admin-tree-leaf">';
        if ($id !== (int)$model->id) {
            echo Html::tag('label', Html::checkbox('NodeTemplate[parentIds][]', in_array($id, (array)$model->parentIds),
                ['value' => $id, 'data-level' => $template['hierarchy_level']]) . ' ' . Html::encode($template['name']), ['data-level' => $template['hierarchy_level']]);
        } else echo Html::encode($template['name']);
        if ($hasChildren) { echo '</summary><div class="admin-tree-children">'; $renderBranch($id); echo '</div></details>'; }
        else echo '</div>';
    }
};
$catalog = (new Query())->select(['code', 'name', 'kind'])->from('craft_item')->where(['active' => 1, 'kind' => ['material', 'tool', 'station']])->orderBy(['name' => SORT_ASC])->all();
$settings = [
    'climate' => ['Климат', 'string'], 'settlement_kind' => ['Тип поселения', ['village' => 'Деревня', 'city' => 'Город']],
    'population' => ['Население', 'number'], 'plot_limit' => ['Лимит участков', 'number'],
    'building_kind' => ['Тип постройки', ['house' => 'Дом', 'forge' => 'Кузница', 'workshop' => 'Мастерская', 'workroom' => 'Рабочее помещение', 'warehouse' => 'Склад', 'canopy' => 'Навес']],
    'level' => ['Уровень постройки', 'number'], 'condition' => ['Прочность', 'number'], 'max_condition' => ['Максимальная прочность', 'number'],
    'area' => ['Площадь', 'number'], 'exposure_class' => ['Защита', ['outdoor' => 'На улице', 'covered' => 'Под навесом', 'indoor' => 'В помещении']],
    'plot_kind' => ['Тип участка', ['land' => 'Земля', 'campsite' => 'Стоянка', 'garden' => 'Огород']],
    'fertility' => ['Плодородие', 'number'], 'allow_building' => ['Можно строить', [0 => 'Нет', 1 => 'Да']], 'unlocked' => ['Открытая грядка', [0 => 'Нет', 1 => 'Да']],
    'storage_capacity' => ['Вместимость мини-склада, слотов', 'number'], 'allow_sales' => ['Разрешена продажа продукции', 'boolean'],
    'map_width' => ['Ширина карты', 'number'], 'map_height' => ['Высота карты', 'number'],
];
try { $defaults = $model->defaults(); $materials = $model->materials(); } catch (Throwable $error) { $defaults = []; $materials = []; }
$kinds = ['WORLD' => 'Мир', 'REGION' => 'Регион', 'SETTLEMENT' => 'Поселение', 'PLOT' => 'Участок', 'BUILDING' => 'Постройка', 'ROOM' => 'Комната', 'BED' => 'Грядка', 'CHEST' => 'Сундук', 'PLACE' => 'Место'];
?>
<?= $form->field($model, 'code')->textInput(['readonly' => !$model->isNewRecord]) ?>
<?= $form->field($model, 'name')->textInput() ?>
<?php $rate = \common\modules\world\models\WorldTime::rate();
if ($model->worldBuildSeconds === null) $model->worldBuildSeconds = (int)$model->build_seconds * $rate; ?>
<?= $form->field($model, 'worldBuildSeconds')->input('number', ['min' => 1, 'max' => 604800 * $rate])->hint('Скорость мира — 1:' . $rate . '. ' . $rate . ' секунд мира = 1 реальная секунда. Коэффициент меняется в редактировании мира. Длительность уже начатых строек сохраняется.') ?>
<div class="admin-wide">Трудоёмкость: <output id="world-duration"><?= \common\modules\world\models\WorldTime::duration((int)$model->worldBuildSeconds) ?></output> времени мира (дни, чч:мм:сс).</div>
<?php $this->registerJs("(function(){var input=document.getElementById('nodetemplate-worldbuildseconds'),output=document.getElementById('world-duration');if(!input||!output)return;function update(){var seconds=Math.max(0,Math.floor(Number(input.value)||0)),days=Math.floor(seconds/86400);seconds%=86400;var parts=[Math.floor(seconds/3600),Math.floor(seconds%3600/60),seconds%60];output.textContent=(days?days+' д. ':'')+parts.map(function(n){return n<10?'0'+n:String(n);}).join(':');}input.addEventListener('input',update);update();})();"); ?>
<div class="admin-wide"><?= $form->field($model, 'kind')->radioList($kinds) ?></div>
<?= $form->field($model, 'enabled')->checkbox() ?>
<?= $form->field($model, 'player_buildable')->checkbox() ?>
<?= $form->field($model, 'hierarchy_level')->textInput(['readonly' => true])->hint('Вычисляется по уровню выбранных родителей.') ?>
<fieldset class="admin-wide admin-template-parents">
    <legend>Разрешённые родители</legend>
    <p>Можно выбрать несколько шаблонов одного уровня. Остальные уровни блокируются автоматически.</p>
    <?= Html::hiddenInput('NodeTemplate[parentIds]', '') ?>
    <div class="admin-parent-tree">
    <?php $renderBranch(0); ?>
    </div>
    <?= Html::error($model, 'parentIds', ['class' => 'help-block']) ?>
</fieldset>
<fieldset class="admin-wide admin-template-requirements">
    <legend>Необходимые материалы и оборудование</legend>
    <p>Материалы расходуются в начале стройки. Инструменты и станции должны быть доступны; они не расходуются.</p>
    <div class="table-responsive"><table class="admin-json-table"><thead><tr><th>Назначение</th><th>Предмет</th><th>Количество</th><th></th></tr></thead><tbody></tbody></table></div>
    <button type="button" class="btn btn-default admin-requirement-add">Добавить строку</button>
    <details><summary>Посмотреть JSON материалов</summary><?= $form->field($model, 'materials_json')->textarea(['rows' => 5, 'readonly' => true])->label(false) ?></details>
</fieldset>
<fieldset class="admin-wide admin-template-settings">
    <legend>Настройки объекта</legend>
    <p class="text-muted">Пустое значение сохраняет стандартное поведение. Дополнительные параметры прежнего JSON сохраняются.</p>
    <div class="admin-settings">
    <?php foreach ($settings as $key => $setting): ?>
        <div class="form-group" data-setting="<?= Html::encode($key) ?>" data-type="<?= $setting[1] === 'boolean' ? 'boolean' : (in_array($key, ['allow_building', 'unlocked'], true) || $setting[1] === 'number' ? 'number' : 'string') ?>">
        <?= Html::label($setting[0], 'setting-' . $key) ?>
        <?php if ($setting[1] === 'boolean'): ?>
            <?= Html::radioList('settings-editor-' . $key, isset($defaults[$key]) ? ($defaults[$key] ? 'true' : 'false') : '', ['' => 'По умолчанию', 'false' => 'Нет', 'true' => 'Да'], ['id' => 'setting-' . $key, 'class' => 'admin-radio-list']) ?>
        <?php elseif (is_array($setting[1])): ?>
            <?php $options = ['' => 'По умолчанию'] + $setting[1];
            if (isset($defaults[$key]) && !array_key_exists($defaults[$key], $options)) $options[$defaults[$key]] = $defaults[$key] . ' (текущее значение)'; ?>
            <?= Html::radioList('settings-editor-' . $key, $defaults[$key] ?? '', $options, ['id' => 'setting-' . $key, 'class' => 'admin-radio-list']) ?>
        <?php else: ?>
            <?= Html::input($setting[1] === 'number' ? 'number' : 'text', 'settings-editor-' . $key, $defaults[$key] ?? '', ['id' => 'setting-' . $key, 'class' => 'form-control', 'min' => 0, 'max' => 1000000]) ?>
        <?php endif ?>
        </div>
    <?php endforeach ?>
    </div>
    <details><summary>Посмотреть JSON настроек</summary><?= $form->field($model, 'defaults_json')->textarea(['rows' => 6, 'readonly' => true])->label(false) ?></details>
</fieldset>
<?= Html::tag('script', Json::htmlEncode(['catalog' => $catalog, 'materials' => $materials, 'defaults' => $defaults]), ['type' => 'application/json', 'id' => 'admin-template-data']) ?>
