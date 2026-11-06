<?php
use common\modules\world\admin\{ControlCatalog,WorldEntityCatalog};
use yii\helpers\Html;
$label=static fn($name)=>WorldEntityCatalog::label($name);
$selected=$entity!=='' ? $entries[$entity] : null;
$title=$selected['label'] ?? 'Управление миром';
$stats=['objects'=>['Объекты','globe','world_node'],'members'=>['Игроки','users','world_membership'],'items'=>['Стопки предметов','cubes','craft_inventory'],'recipes'=>['Рецепты','gavel','craft_recipe'],'builds'=>['Стройки','building','world_build'],'trades'=>['Сделки за 24 ч','exchange','world_market_trade']];
?>
<div class="world-control">
<?= $this->render('_header',compact('group','title')) ?>
<?php if($report): ?>
<div class="wc-stats"><?php foreach($stats as $id=>[$name,$icon,$table]): ?>
<?= Html::a('<span><i class="fa fa-'.$icon.'" aria-hidden="true"></i>'.$name.'</span><strong>'.number_format($report['counts'][$id],0,',',' ').'</strong>',['index','entity'=>$table],['class'=>'wc-stat']) ?>
<?php endforeach ?></div>
<?php if(in_array($group,['overview','economy','npc'],true)): ?>
<div class="wc-grid"><div>
<section class="wc-panel"><h2><i class="fa fa-refresh" aria-hidden="true"></i>Пополнение источников NPC</h2>
<p class="wc-muted">Деньги поступают из оплаченных покупок. Остаток переносится; сутки считаются по Москве.</p>
<?php if(!$report['funding']): ?><p class="wc-note">Правила появятся после первого планового обхода NPC или продажи. Начальные условия: 80%, 500 Cr в сутки, раз в час.</p><?php endif ?>
<?php foreach($report['funding'] as $fund): $today=date_create('now',new DateTimeZone('Europe/Moscow'))->format('Y-m-d'); ?>
<h3><?= Html::a(Html::encode($fund['name']),['view','entity'=>'world_node','key'=>['id'=>$fund['site_id']]]) ?> <span class="wc-badge"><?= $fund['enabled']?'Включено':'Остановлено' ?></span></h3>
<div class="wc-table"><table class="table"><tbody>
<tr><td>Выручка с начала учёта</td><td class="text-right wc-number"><?= Html::encode($fund['sales_cr']) ?> Cr</td></tr>
<tr><td>Доступно для будущих пополнений</td><td class="text-right wc-number"><?= Html::encode($fund['eligible_cr']) ?> Cr</td></tr>
<tr><td>Направлено сегодня / лимит</td><td class="text-right wc-number"><?= Html::encode(($fund['day_key']===$today?$fund['day_spent_cr']:'0').' / '.$fund['daily_limit_cr']) ?> Cr</td></tr>
<tr><td>Доля / интервал / цель на источник</td><td class="text-right"><?= Html::encode(($fund['share_bps']/100).'% · '.($fund['interval_seconds']/3600).' ч · '.$fund['target_cr'].' Cr') ?></td></tr>
<tr><td>Следующий допустимый запуск</td><td class="text-right"><?= $fund['last_refill_at'] ? Html::encode(Yii::$app->formatter->asDatetime((int)$fund['last_refill_at']+(int)$fund['interval_seconds'])) : 'При ближайшем обходе' ?></td></tr>
</tbody></table></div><div class="wc-actions">
<?= Html::a('<i class="fa fa-sliders"></i> Настройки',['edit','entity'=>'world_npc_funding','key'=>['id'=>$fund['id']]],['class'=>'btn btn-default']) ?>
<?= Html::a('История пополнений',['index','entity'=>'world_npc_funding_run','filter'=>['site_id'=>$fund['site_id']]],['class'=>'btn btn-link']) ?>
</div><?php endforeach ?></section>
<section class="wc-panel"><h2><i class="fa fa-line-chart"></i>Выручка NPC за 24 часа</h2><div class="wc-table"><table class="table"><thead><tr><th>Источник</th><th>Покупки</th><th>Получено, Cr</th><th>В резерв, Cr</th></tr></thead><tbody>
<?php foreach($report['sales'] as $sale): ?><tr><td><?= Html::encode(['source'=>'Источники','warehouse'=>'Склад','market'=>'Рынок'][$sale['channel']] ?? $sale['channel']) ?></td><td><?= (int)$sale['operations'] ?></td><td><?= Html::encode($sale['amount_cr']) ?></td><td><?= Html::encode($sale['eligible_cr']) ?></td></tr><?php endforeach ?>
<?php if(!$report['sales']): ?><tr><td colspan="4" class="wc-muted">Покупок за последние сутки нет.</td></tr><?php endif ?>
</tbody></table></div></section>
</div><div><section class="wc-panel"><h2><i class="fa fa-money"></i>Счета всех объектов</h2><div class="wc-table"><table class="table"><thead><tr><th>Назначение</th><th>Свободно, Cr</th><th>Резерв, Cr</th></tr></thead><tbody>
<?php foreach($report['roles'] as $role): ?><tr><td><?= Html::a(Html::encode(['budget'=>'Бюджет','treasury'=>'Казна','reserve'=>'Резерв','loss'=>'Потери'][$role['role']] ?? $role['role']),['index','entity'=>'economy_account','filter'=>['role'=>$role['role']]]) ?></td><td class="wc-number"><?= Html::encode($role['available_cr']) ?></td><td class="wc-number"><?= Html::encode($role['reserved_cr']) ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<section class="wc-panel"><h2><i class="fa fa-history"></i>Последние исправления</h2>
<?php foreach($report['corrections'] as $correction): ?><p><?= Html::a(Html::encode($entries[$correction['entity']]['label'] ?? $correction['entity']),['view','entity'=>'world_admin_correction','key'=>['id'=>$correction['id']]]) ?><br><small class="wc-muted"><?= Html::encode($correction['username'].' · '.Yii::$app->formatter->asDatetime($correction['created_at'])) ?></small><br><?= Html::encode($correction['comment']) ?></p><?php endforeach ?>
<?php if(!$report['corrections']): ?><p class="wc-muted">Ручных исправлений пока нет.</p><?php endif ?></section></div></div>
<?php endif ?>
<?php if(in_array($group,['overview','people'],true)): ?><section class="wc-panel"><h2><i class="fa fa-users"></i>Новые участники</h2><div class="wc-actions"><?php foreach($report['players'] as $player): ?><?= Html::a(Html::encode($player['username']),['index','entity'=>'world_membership','filter'=>['user_id'=>$player['user_id']]],['class'=>'btn btn-default']) ?><?php endforeach ?></div></section><?php endif ?>
<?= $this->render('_slices',compact('group','report')) ?>
<section class="wc-panel"><h2><i class="fa fa-th-large"></i><?= $group==='overview'?'Быстрый доступ':Html::encode(ControlCatalog::groups()[$group][0]) ?></h2><div class="wc-catalog">
<?php $primary=$group==='overview'?['world_node','craft_item','craft_recipe','craft_inventory','world_membership','world_admin_correction']:ControlCatalog::primary(); foreach($entries as $table=>$entry): if(!in_array($table,$primary,true) || ($group!=='overview' && $entry['group']!==$group)) continue; ?>
<?= Html::a(Html::encode($entry['label']).'<small>'.Html::encode($table).'</small>',['index','entity'=>$table]) ?>
<?php endforeach ?></div>
<?php if($group!=='overview'): ?><details class="wc-technical"><summary>Все записи раздела · расширенное управление</summary><div class="wc-catalog">
<?php foreach($entries as $table=>$entry): if($entry['group']!==$group || in_array($table,$primary,true)) continue; ?><?= Html::a(Html::encode($entry['label']).'<small>'.Html::encode($table).'</small>',['index','entity'=>$table]) ?><?php endforeach ?>
</div></details><?php endif ?></section>
<?php else: $schema=Yii::$app->db->schema->getTableSchema($entity); ?>
<section class="wc-panel">
<?= Html::beginForm(['index'],'get',['class'=>'wc-search']) ?><?= Html::hiddenInput('entity',$entity) ?>
<label>Название, код или номер<?= Html::textInput('q',$q,['class'=>'form-control','maxlength'=>120]) ?></label>
<?= Html::submitButton('<i class="fa fa-search"></i> Найти',['class'=>'btn btn-primary']) ?><?= Html::a('Сбросить',['index','entity'=>$entity],['class'=>'btn btn-default']) ?>
<details<?= array_filter($filter,static fn($value)=>$value!=='')?' open':'' ?>><summary>Точные фильтры</summary><div class="wc-filter-fields"><?php foreach($fields as $field): ?><label><?= Html::encode($label($field)) ?><?= Html::textInput('filter['.$field.']',$filter[$field] ?? '',['class'=>'form-control','maxlength'=>120]) ?></label><?php endforeach ?></div></details>
<?= Html::endForm() ?>
<p class="wc-muted">Найдено: <?= $provider->totalCount ?> · <?= Html::encode($entity) ?></p>
<div class="wc-table"><?= \yii\grid\GridView::widget(['dataProvider'=>$provider,'layout'=>'{items}{pager}','tableOptions'=>['class'=>'table table-hover'],
 'columns'=>array_merge([['label'=>'Запись','format'=>'raw','value'=>static function($row) use($entity,$schema){ $key=array_intersect_key($row,array_flip($schema->primaryKey)); return $key ? Html::a('Открыть',['view','entity'=>$entity,'key'=>$key],['class'=>'btn btn-default btn-sm']) : '—'; }]],array_map(static fn($name)=>['attribute'=>$name,'label'=>$label($name),'format'=>'text'],array_slice($columns,0,11)))]) ?></div>
</section><?php endif ?>
</div>
