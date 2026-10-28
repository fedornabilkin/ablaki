<?php use yii\helpers\Html; $this->title='Проверка каталога'; ?>
<h1>Предварительная проверка</h1><p>Ссылки и зависимости проверены. Записи с совпадающим кодом будут обновлены; остальные добавлены. Инвентари не удаляются.</p>
<ul><?php foreach($preview['counts'] as $group=>$count): ?><li><?= Html::encode($group) ?>: <?= (int)$count ?></li><?php endforeach ?></ul>
<details><summary>Данные изменений</summary><pre><?= Html::encode(json_encode(json_decode($document,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></details>
<?= Html::beginForm(['import'],'post') ?><?= Html::hiddenInput('document',$document) ?><?= Html::hiddenInput('digest',$preview['digest']) ?>
<?= Html::submitButton('Применить изменения',['class'=>'btn btn-success']) ?> <?= Html::a('Отмена',['index'],['class'=>'btn btn-default']) ?><?= Html::endForm() ?>
