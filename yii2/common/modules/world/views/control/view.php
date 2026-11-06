<?php
use common\modules\world\admin\{ControlCatalog,WorldEntityCatalog};
use yii\helpers\Html;
$catalog=new ControlCatalog(Yii::$app->db); $entries=$catalog->entries(); $group=$entries[$entity]['group']; $title=$entries[$entity]['label']; $schema=$catalog->schema($entity);
?>
<div class="world-control"><?= $this->render('_header',compact('group','title')) ?>
<section class="wc-panel"><div class="wc-heading"><p class="wc-muted"><?= Html::encode($entity.' · '.implode(' / ',$key)) ?></p><div class="wc-actions">
<?= Html::a('К списку',['index','entity'=>$entity],['class'=>'btn btn-default']) ?>
<?php if(!ControlCatalog::immutable($entity)): ?><?= Html::a('<i class="fa fa-pencil"></i> Исправить с комментарием',['edit','entity'=>$entity,'key'=>$key],['class'=>'btn btn-warning']) ?><?php endif ?>
<?php if($entity==='world_node'): ?><?= Html::a('Редактор объекта',['/world/node/update','id'=>$row['id']],['class'=>'btn btn-default']) ?><?php endif ?>
<?php if($entity==='world_template'): ?><?= Html::a('Редактор шаблона',['/world/template/update','id'=>$row['id']],['class'=>'btn btn-default']) ?><?php endif ?>
</div></div><div class="wc-table"><table class="table"><tbody>
<?php foreach($row as $field=>$value): ?><tr><th><?= Html::encode(WorldEntityCatalog::label($field)) ?><br><small class="wc-muted"><?= Html::encode($field) ?></small></th><td>
<?php if(str_ends_with($field,'_json')): ?><pre><?= Html::encode(json_encode(json_decode($value ?? '',true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: $value) ?></pre>
<?php elseif(str_ends_with($field,'_at') && is_numeric($value) && (int)$value>0): ?><?= Html::encode(Yii::$app->formatter->asDatetime((int)$value)) ?> <small class="wc-muted"><?= Html::encode((string)$value) ?></small>
<?php else: ?><?= Html::encode($value===null?'—':(string)$value) ?><?php endif ?>
<?php foreach($schema->foreignKeys as $foreign): $parent=$foreign[0]; unset($foreign[0]); if(!isset($entries[$parent],$foreign[$field])) continue; $parentKey=[]; foreach($foreign as $local=>$remote) $parentKey[$remote]=$row[$local]; if(in_array(null,$parentKey,true) || array_diff(array_keys($parentKey),$catalog->schema($parent)->primaryKey)) continue; ?>
<?= Html::a(' ↗ '.$entries[$parent]['label'],['view','entity'=>$parent,'key'=>$parentKey]) ?>
<?php endforeach ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<?php if($history): ?><section class="wc-panel"><h2><i class="fa fa-history"></i>История исправлений</h2><?php foreach($history as $entry): ?><p><?= Html::a(Html::encode(Yii::$app->formatter->asDatetime($entry['created_at'])),['view','entity'=>'world_admin_correction','key'=>['id'=>$entry['id']]]) ?> · администратор №<?= (int)$entry['author_id'] ?><br><?= Html::encode($entry['comment']) ?></p><?php endforeach ?></section><?php endif ?>
</div>
