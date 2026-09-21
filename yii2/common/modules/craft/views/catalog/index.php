<?php use yii\helpers\Html; $this->title='Каталог крафта'; ?>
<h1><?= Html::encode($this->title) ?></h1>
<section class="well">
<h2>Оплата крафта</h2>
<?= Html::beginForm(['settings'],'post') ?>
<?= Html::checkbox('charge_credits',$chargeCredits,['value'=>'1','uncheck'=>'0','label'=>'Списывать кредиты за создание предметов','role'=>'switch']) ?>
<p>Пока настраиваем мастерскую, оставьте выключенным. Ресурсы расходуются, кредиты не списываются. Цены рецептов сохраняются для последующего включения.</p>
<?= Html::submitButton('Сохранить настройку',['class'=>'btn btn-primary']) ?>
<?= Html::endForm() ?>
</section>
<p>Предметы, рецепты, станции и категории связаны стабильными кодами. Снятие флага «активен» отключает запись без удаления инвентаря игроков.</p>
<?php if($error): ?><div class="alert alert-danger"><?= Html::encode($error) ?></div><?php endif ?>
<p><?= Html::a('Экспорт каталога JSON',['export'],['class'=>'btn btn-primary']) ?></p>
<p><?= Html::a('Скачать начальный каталог (44 предмета, 34 рецепта)',['template']) ?> — готовый файл для предварительной проверки и импорта.</p>
<?= Html::beginForm(['preview'],'post',['enctype'=>'multipart/form-data']) ?>
<label>Импорт JSON (до 2 МБ) <?= Html::fileInput('catalog_file',null,['accept'=>'.json,application/json','required'=>true]) ?></label>
<?= Html::submitButton('Проверить импорт',['class'=>'btn btn-warning']) ?>
<?= Html::endForm() ?>
<?php foreach(['categories'=>'Категории','items'=>'Предметы','stations'=>'Станции','recipes'=>'Рецепты'] as $group=>$name): ?>
<h2><?= Html::encode($name) ?> <?= Html::a('Добавить',['edit','group'=>$group],['class'=>'btn btn-success btn-sm']) ?></h2>
<table class="table table-striped"><thead><tr><th>Код</th><th>Название</th><th>Состояние</th></tr></thead><tbody>
<?php foreach($catalog[$group] as $r): ?><tr><td><?= Html::encode($r['code']) ?></td><td><?= Html::a(Html::encode($r['name']),['edit','group'=>$group,'code'=>$r['code']]) ?></td><td><?= !isset($r['active'])||$r['active']?'Активен':'Отключён' ?></td></tr><?php endforeach ?>
</tbody></table><?php endforeach ?>
