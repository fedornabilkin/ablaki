<?php
use yii\helpers\Html;
$this->title = 'Настройки авторизации';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Вход через Telegram</h3></div>
<div class="box-body">
<?= Html::beginForm(['index'], 'post') ?>
<?= Html::checkbox('enabled', $settings['enabled'], ['value' => '1', 'uncheck' => '0', 'label' => 'Разрешить вход через Telegram']) ?>
<p>Бот: <?= $settings['bot_username'] ? Html::encode('@' . $settings['bot_username']) : 'Ожидается подключение бота' ?></p>
<p><?= $available ? 'Вход доступен.' : 'Для входа нужны подключённый бот, общий ключ API и включённый переключатель.' ?></p>
<?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?>
<?= Html::endForm() ?>
</div></div>
