<?php
use backend\support\AdminNavigation;
use common\modules\world\admin\ControlCatalog;
use mdm\admin\components\Helper;
use yii\helpers\Html;
$items=AdminNavigation::allowedItems();
$items=AdminNavigation::activate($items,Yii::$app->controller->route,Yii::$app->request->queryParams,(new ControlCatalog(Yii::$app->db))->entries());
?>
<aside class="main-sidebar"><section class="sidebar">
<?= dmstr\widgets\Menu::widget(['options'=>['class'=>'sidebar-menu tree','data-widget'=>'tree'],'items'=>$items]) ?>
<?php if (!Yii::$app->user->isGuest && Helper::checkRoute('/maintenance/clear-cache')): ?>
<div class="sidebar-form"><?= Html::beginForm(['/maintenance/clear-cache'],'post') ?>
<?= Html::submitButton('<i class="fa fa-refresh" aria-hidden="true"></i> Сбросить кэш',['class'=>'btn btn-default btn-block','title'=>'Сбросить кэш прав доступа и меню']) ?>
<?= Html::endForm() ?></div><?php endif ?>
</section></aside>
