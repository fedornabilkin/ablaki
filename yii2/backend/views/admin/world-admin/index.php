<?php
use yii\helpers\Html;

$this->title = 'Управление миром';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-body">
    <p>Выберите поселение для управления каталогом построек или откройте объект для просмотра его состава.</p>
    <dl class="dl-horizontal">
        <?php foreach (['schema_ready' => 'Подготовка данных', 'world_read' => 'Просмотр мира', 'world_write' => 'Действия в мире',
            'storage_v2' => 'Размещение имущества', 'economy_tick' => 'Экономические расчёты'] as $flag => $label): ?>
            <dt><?= Html::encode($label) ?></dt><dd><?= $flags[$flag] ? 'Готово / включено' : 'Не готово / выключено' ?></dd>
        <?php endforeach ?>
    </dl>
    <?php if (!$flags['schema_ready']): ?>
        <p class="alert alert-warning">Данные мира ещё не подготовлены для этой версии приложения. Необходимо завершить установку миграций. Сброс кэша не включает игровые функции.</p>
    <?php else: ?>
        <?php if ($flags['world_read']): ?>
            <p><?= Html::a('Журнал изменений', ['audit'], ['class' => 'btn btn-default']) ?></p>
        <?php endif ?>
        <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?>
            <div class="form-group">
                <?= Html::label('Название', 'world-q') ?>
                <?= Html::textInput('q', $q, ['id' => 'world-q', 'class' => 'form-control', 'maxlength' => 120]) ?>
            </div>
            <div class="form-group">
                <?= Html::label('Тип', 'world-type') ?>
                <?= Html::dropDownList('type', $type, ['' => 'Все', 'WORLD' => 'Мир', 'REGION' => 'Регион',
                    'SETTLEMENT' => 'Поселение', 'BUILDING' => 'Постройка', 'ROOM' => 'Комната', 'PLOT' => 'Участок', 'BED' => 'Грядка'],
                    ['id' => 'world-type', 'class' => 'form-control']) ?>
            </div>
            <?= Html::submitButton('Найти', ['class' => 'btn btn-default']) ?>
        <?= Html::endForm() ?>
        <?= $this->render('_nodes', ['provider' => $provider]) ?>
    <?php endif ?>
</div></div>
