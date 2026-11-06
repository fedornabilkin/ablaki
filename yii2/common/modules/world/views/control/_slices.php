<?php
use yii\helpers\Html;
$distributions = [
    'objects' => [
        ['Объекты по типам','globe','Тип','world_node','node_type','objects'],
        ['Строительство','building','Состояние','world_build','status','builds'],
        ['Посевы','leaf','Состояние','world_crop_cycle','status','crops'],
    ],
    'people' => [['Работа на источниках','users','Состояние','world_source_work','status','work']],
];
?>
<?php if(isset($distributions[$group])): ?><div class="wc-grid">
<?php foreach($distributions[$group] as [$title,$icon,$heading,$entity,$field,$key]): $rows=$report[$key]; ?>
<?= $this->render('_distribution',compact('title','icon','heading','entity','field','rows')) ?>
<?php endforeach ?></div><?php endif ?>
<?php if($group==='items'): ?><section class="wc-panel"><h2><i class="fa fa-cubes" aria-hidden="true"></i>Самые большие запасы</h2>
<p class="wc-muted">12 самых многочисленных предметов во всех хранилищах. Учтены и свободные, и зарезервированные предметы.</p>
<div class="wc-table"><table class="table"><thead><tr><th>Предмет</th><th>Количество</th><th>Стопки</th></tr></thead><tbody>
<?php foreach($report['stock'] as $row): ?><tr><td><?= Html::a(Html::encode($row['name']),['index','entity'=>'craft_inventory','filter'=>['item_id'=>$row['item_id']]]) ?></td><td><?= number_format((int)$row['quantity'],0,',',' ') ?></td><td><?= (int)$row['stacks'] ?></td></tr><?php endforeach ?>
<?php if(!$report['stock']): ?><tr><td colspan="3" class="wc-muted">Запасов пока нет.</td></tr><?php endif ?>
</tbody></table></div></section><?php endif ?>
<?php if($group==='craft'): ?><section class="wc-panel"><h2><i class="fa fa-gavel" aria-hidden="true"></i>Изготовление за 24 часа</h2>
<p class="wc-muted">12 наиболее используемых рецептов; считаются операции изготовления, а не количество предметов в партии.</p>
<div class="wc-table"><table class="table"><thead><tr><th>Результат рецепта</th><th>Операции</th></tr></thead><tbody>
<?php foreach($report['craft'] as $row): ?><tr><td><?= Html::a(Html::encode($row['name']),['index','entity'=>'craft_history','filter'=>['recipe_id'=>$row['recipe_id']]]) ?></td><td><?= (int)$row['operations'] ?></td></tr><?php endforeach ?>
<?php if(!$report['craft']): ?><tr><td colspan="2" class="wc-muted">За последние сутки ничего не изготовлено.</td></tr><?php endif ?>
</tbody></table></div></section><?php endif ?>
<?php if($group==='npc'): $reasons=['funded'=>'Пополнено','no_revenue'=>'Нет новой выручки','daily_limit'=>'Дневной лимит исчерпан','no_cash'=>'Не хватает денег бота','targets_funded'=>'Источники обеспечены']; ?>
<section class="wc-panel"><h2><i class="fa fa-clock-o" aria-hidden="true"></i>Последние обходы источников</h2>
<div class="wc-table"><table class="table"><thead><tr><th>Время</th><th>Стоянка</th><th>Результат</th><th>Пополнено, Cr</th></tr></thead><tbody>
<?php foreach($report['runs'] as $row): ?><tr><td><?= Html::a(Html::encode(Yii::$app->formatter->asDatetime($row['created_at'])),['view','entity'=>'world_npc_funding_run','key'=>['id'=>$row['id']]]) ?></td><td><?= Html::encode($row['name']) ?></td><td><?= Html::encode($reasons[$row['reason']] ?? $row['reason']) ?></td><td><?= Html::encode($row['amount_cr']) ?></td></tr><?php endforeach ?>
<?php if(!$report['runs']): ?><tr><td colspan="4" class="wc-muted">Плановых обходов пока нет.</td></tr><?php endif ?>
</tbody></table></div></section><?php endif ?>
