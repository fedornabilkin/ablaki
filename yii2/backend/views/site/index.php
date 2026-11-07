<?php
use backend\support\EntityDisplay;
use backend\widgets\WorldSummary;
use common\modules\world\admin\ControlCatalog;
use common\modules\world\assets\ControlAsset;
use yii\helpers\Html;
ControlAsset::register($this);
$this->title='Обзор сайта';
?>
<div class="world-control admin-home">
<section class="wc-panel admin-welcome"><div><span class="admin-eyebrow">Панель управления</span><h2>Сайт и мир — в одном месте</h2><p class="wc-muted">Состояние игры, экономика и задачи, которым нужно внимание.</p></div>
<?php if($worldReport): ?><?= Html::a('<i class="fa fa-globe"></i> Управление миром',['/world/control/index'],['class'=>'btn btn-primary']) ?><?php endif ?></section>
<?php if($worldReport): ?>
<?= WorldSummary::widget(['counts'=>$worldReport['counts']]) ?>
<section class="wc-panel"><h2><i class="fa fa-compass" aria-hidden="true"></i>Разделы мира</h2><div class="admin-shortcuts">
<?php foreach(ControlCatalog::groups() as $id=>[$name,$icon]): if($id==='overview') continue; ?>
<?= Html::a('<i class="fa fa-'.$icon.'" aria-hidden="true"></i><span>'.Html::encode($name).'</span><i class="fa fa-angle-right" aria-hidden="true"></i>',['/world/control/index','group'=>$id]) ?>
<?php endforeach ?></div></section>
<div class="wc-grid"><section class="wc-panel"><h2><i class="fa fa-money"></i>Финансы мира</h2><div class="wc-table"><table class="table"><thead><tr><th>Счета</th><th>Доступно, Cr</th><th>Зарезервировано, Cr</th></tr></thead><tbody>
<?php foreach($worldReport['roles'] as $role): ?><tr><td><?= Html::a(Html::encode(['budget'=>'Бюджеты','treasury'=>'Казна','reserve'=>'Резерв','loss'=>'Потери'][$role['role']]??$role['role']),['/world/control/index','entity'=>'economy_account','filter'=>['role'=>$role['role']]]) ?></td><td><?= Html::encode($role['available_cr']) ?></td><td><?= Html::encode($role['reserved_cr']) ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<section class="wc-panel"><h2><i class="fa fa-refresh"></i>Пополнение источников NPC</h2>
<?php foreach(array_slice($worldReport['funding'],0,5) as $fund): ?><div class="admin-list-row"><div><?= EntityDisplay::link('world_node',['id'=>$fund['site_id'],'name'=>$fund['name']]) ?><small class="wc-muted"><?= $fund['enabled']?'Пополнение включено':'Пополнение остановлено' ?> · <?= Html::encode(($fund['share_bps']/100).'% выручки') ?></small></div><strong><?= Html::encode($fund['daily_limit_cr']) ?> Cr/сут.</strong></div><?php endforeach ?>
<?php if(!$worldReport['funding']): ?><p class="wc-muted">Правила появятся после первого обхода NPC или продажи.</p><?php endif ?>
<?= Html::a('Выручка и правила пополнения →',['/world/control/index','group'=>'npc'],['class'=>'btn btn-default']) ?></section></div>
<section class="wc-panel"><h2><i class="fa fa-users"></i>Новые участники мира</h2><div class="wc-actions">
<?php foreach($worldReport['players'] as $player): ?><?= Html::a('<i class="fa fa-user-circle" aria-hidden="true"></i> '.Html::encode($player['username']),['/user/admin/info','id'=>$player['user_id']],['class'=>'btn btn-default']) ?><?php endforeach ?>
<?php if(!$worldReport['players']): ?><p class="wc-muted">Пока нет участников.</p><?php endif ?></div></section>
<?php endif ?>
<div class="admin-work-grid">
<?php foreach([
 ['title'=>'Задачи модерации','icon'=>'tasks','models'=>$todoProvider->models,'table'=>'todo','url'=>['/todo/index']],
 ['title'=>'Новые профили','icon'=>'user-circle','models'=>$personProvider->models,'table'=>'persone','url'=>['/person/index']],
 ['title'=>'Обсуждения','icon'=>'comments','models'=>$themeProvider->models,'table'=>'forum_theme','url'=>['/forum/theme/index']],
] as $block): ?><section class="wc-panel"><h2><i class="fa fa-<?= $block['icon'] ?>"></i><?= $block['title'] ?></h2>
<?php EntityDisplay::warm($block['models']); foreach($block['models'] as $model): ?><div class="admin-list-row"><?= EntityDisplay::link($block['table'],$model->attributes) ?></div><?php endforeach ?>
<?php if(!$block['models']): ?><p class="wc-muted">Новых записей нет.</p><?php endif ?>
<?= Html::a('Открыть раздел →',$block['url'],['class'=>'btn btn-default']) ?></section><?php endforeach ?>
<section class="wc-panel"><h2><i class="fa fa-percent"></i>Комиссии за сегодня</h2>
<?php foreach($commission as $row): ?><div class="admin-list-row"><span><?= Html::encode($row['type']) ?><small class="wc-muted"><?= (int)$row['count'] ?> операций</small></span><strong><?= Html::encode(EntityDisplay::credits($row['amount'])) ?> Cr</strong></div><?php endforeach ?>
<?php if(!$commission): ?><p class="wc-muted">Сегодня поступлений ещё нет.</p><?php endif ?><?= Html::a('История комиссий →',['/commission/index'],['class'=>'btn btn-default']) ?></section>
<section class="wc-panel"><h2><i class="fa fa-user-times"></i>Неактивные аккаунты</h2><p class="wc-muted">На проверку: <?= $userCleanProvider->totalCount ?></p>
<div class="wc-actions"><?php foreach(array_slice($userCleanProvider->models,0,5) as $model): ?><?= EntityDisplay::link('user',$model->attributes) ?><?php endforeach ?></div>
<?php if(!$userCleanProvider->totalCount): ?><p class="wc-muted">Нет аккаунтов, требующих проверки.</p><?php endif ?></section>
</div></div>
