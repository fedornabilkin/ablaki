<?php
use yii\helpers\Html;
$labels = ['active'=>'Действует', 'building'=>'Строится', 'completed'=>'Завершено', 'cancelled'=>'Отменено',
    'paused'=>'Приостановлено', 'blocked'=>'Ожидает условий', 'growing'=>'Растёт', 'ready'=>'Готово',
    'harvested'=>'Собрано', 'dead'=>'Погибло', 'world'=>'Мир', 'region'=>'Регион', 'city'=>'Город',
    'site'=>'Участок', 'room'=>'Помещение', 'container'=>'Контейнер'];
if($field==='node_type') $labels=['WORLD'=>'Мир','REGION'=>'Регион','SETTLEMENT'=>'Поселение','PLOT'=>'Участок',
    'BUILDING'=>'Постройка','ROOM'=>'Комната','BED'=>'Грядка','CHEST'=>'Сундук','PLACE'=>'Место'];
?>
<section class="wc-panel"><h2><i class="fa fa-<?= Html::encode($icon) ?>" aria-hidden="true"></i><?= Html::encode($title) ?></h2>
<div class="wc-table"><table class="table"><thead><tr><th><?= Html::encode($heading) ?></th><th>Количество</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= Html::a(Html::encode($labels[$row[$field]] ?? $row[$field]),['index','entity'=>$entity,'filter'=>[$field=>$row[$field]]]) ?></td><td><?= number_format((int)$row['quantity'],0,',',' ') ?></td></tr><?php endforeach ?>
<?php if(!$rows): ?><tr><td colspan="2" class="wc-muted">Записей пока нет.</td></tr><?php endif ?>
</tbody></table></div></section>
