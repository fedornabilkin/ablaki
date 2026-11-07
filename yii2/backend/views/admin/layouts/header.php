<?php
use yii\helpers\Html;
?>
<header class="main-header">
<?= Html::a('<span class="logo-mini">A</span><span class="logo-lg"><strong>Аблакин</strong> · админка</span>', ['/site/index'], ['class'=>'logo']) ?>
<nav class="navbar navbar-static-top" aria-label="Управление сайтом">
<a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button" aria-label="Открыть меню"><span class="sr-only">Меню</span></a>
<div class="navbar-custom-menu"><ul class="nav navbar-nav">
<?php if (!Yii::$app->user->isGuest): ?>
<li><?= Html::a('<i class="fa fa-user-circle" aria-hidden="true"></i> '.Html::encode(Yii::$app->user->identity->username), ['/user/admin/info','id'=>Yii::$app->user->id]) ?></li>
<li><?= Html::a('<i class="fa fa-sign-out" aria-hidden="true"></i> <span class="hidden-xs">Выйти</span>', ['/user/security/logout'], ['data-method'=>'post','aria-label'=>'Выйти']) ?></li>
<?php endif ?>
</ul></div></nav></header>
