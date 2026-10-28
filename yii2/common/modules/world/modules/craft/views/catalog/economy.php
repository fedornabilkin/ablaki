<?php
use yii\helpers\Html;
$this->title = 'Профиль экономики закрытого теста';
?>
<h1><?= Html::encode($this->title) ?></h1>
<p><?= Html::a('Каталог и настройки крафта', ['index']) ?></p>
<p>Действующая версия: <?= $current ? (int)$current['version'] : 'не опубликована' ?>. Публикация применяется к новым действиям. Принятые партии добычи, стройки и посевы сохраняют условия. Кредиты, заказы и награды автоматически не выдаются.</p>
<p>Участие назначается ролью <code>world-tester</code> в существующем управлении RBAC. Для подготовки роли используется <code>world-setup/economy-access</code>. Поле когорты не предоставляет доступ.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= Html::encode($error) ?></div><?php endif ?>
<?= Html::beginForm(['economy'], 'post') ?>
<p><label for="economy-document">Настройки профиля, включая квоты</label></p>
<?= Html::textarea('document', is_string($document) ? $document : '', ['id' => 'economy-document', 'rows' => 24, 'class' => 'form-control', 'required' => true]) ?>
<p><label for="economy-reason">Причина изменения</label></p>
<?= Html::textInput('reason', is_string($reason) ? $reason : '', ['id' => 'economy-reason', 'maxlength' => 500, 'required' => true, 'class' => 'form-control']) ?>
<p><?= Html::submitButton('Предварительная проверка', ['class' => 'btn btn-default']) ?></p>
<?= Html::endForm() ?>
<?php if ($preview): ?>
<section class="well">
<h2>Изменения проверены</h2>
<p>Новых кредитов: 0 Cr. Условия незавершённых операций сохраняются. Изменение действующего каталога после этой проверки потребует повторного расчёта.</p>
<pre><?= Html::encode(json_encode(['counts' => $preview['counts'], 'profile_digest' => $preview['profile_digest']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<?= Html::beginForm(['economy'], 'post') ?>
<?= Html::hiddenInput('document', $document) ?><?= Html::hiddenInput('reason', $reason) ?><?= Html::hiddenInput('digest', $preview['digest']) ?>
<?= Html::submitButton('Опубликовать проверенный профиль', ['name' => 'publish', 'value' => '1', 'class' => 'btn btn-primary']) ?>
<?= Html::endForm() ?>
</section>
<?php endif ?>
<h2>История публикаций</h2>
<table class="table table-striped"><thead><tr><th>Версия</th><th>Автор</th><th>Время</th><th>Причина</th><th>Восстановление</th></tr></thead><tbody>
<?php foreach ($history as $entry): ?><tr><td><?= (int)$entry['version'] ?></td><td><?= (int)$entry['author_user_id'] ?></td><td><?= Html::encode(date('Y-m-d H:i:s', $entry['created_at'])) ?></td><td><?= Html::encode($entry['reason']) ?></td><td><?= Html::a('Загрузить для новой публикации', ['economy', 'revision' => $entry['id']]) ?></td></tr><?php endforeach ?>
</tbody></table>
<nav aria-label="Страницы публикаций">
<?php if ((int)$page > 1) echo Html::a('Предыдущая', ['economy', 'page' => (int)$page - 1]); ?>
<?php if ((int)$page * 20 < $total) echo Html::a('Следующая', ['economy', 'page' => (int)$page + 1]); ?>
</nav>
