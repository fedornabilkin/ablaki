<?php
use common\modules\world\admin\{ControlCatalog,WorldEntityCatalog};
use common\modules\world\models\admin\WorldEmergencyEdit;
use yii\helpers\Html;
use backend\support\EntityDisplay;
use backend\widgets\EntityReferenceInput;
$catalog=new ControlCatalog(Yii::$app->db); $entry=$catalog->entries()[$entity]; $group=$entry['group']; $title='Исправление: '.$entry['label']; $schema=$catalog->schema($entity);
?>
<div class="world-control"><?= $this->render('_header',compact('group','title')) ?><section class="wc-panel">
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= Html::encode($error) ?></div><?php endif ?>
<?php if($preview): ?>
<h2>Проверьте изменения</h2><div class="wc-table"><table class="table"><thead><tr><th>Поле</th><th>Было</th><th>Станет</th></tr></thead><tbody>
<?php foreach($preview['changes'] as $field=>$value): ?><tr><th><?= Html::encode(WorldEntityCatalog::label($field)) ?></th><td class="wc-diff-old"><pre><?= EntityDisplay::value($entity,$field,$preview['before'][$field],$preview['before']) ?? Html::encode($preview['before'][$field]===null?'NULL':(string)$preview['before'][$field]) ?></pre></td><td class="wc-diff-new"><pre><?= EntityDisplay::value($entity,$field,$value,array_replace($preview['before'],$preview['changes'])) ?? Html::encode($value===null?'NULL':(string)$value) ?></pre></td></tr><?php endforeach ?>
</tbody></table></div><p><strong>Причина:</strong> <?= Html::encode($preview['comment']) ?></p>
<?= Html::beginForm(['apply'],'post') ?><?= Html::hiddenInput('token',$token) ?><div class="wc-actions"><?= Html::submitButton('Подтвердить исправление',['class'=>'btn btn-warning']) ?><?= Html::a('Отмена',['view','entity'=>$entity,'key'=>$key],['class'=>'btn btn-default']) ?></div><?= Html::endForm() ?>
<?php else: ?>
<p class="wc-note">Отметьте только поля, которые нужно исправить. Следующий шаг покажет сравнение. Автор, причина и прежние значения сохранятся в истории. Принятые обязательства и связи проверяются перед записью.</p>
<?= Html::beginForm(['edit','entity'=>$entity,'key'=>$key,'advanced'=>Yii::$app->request->get('advanced')?1:null],'post') ?><?= Html::hiddenInput('version',WorldEmergencyEdit::digest($row)) ?>
<?php $routine=$entity==='world_npc_funding' && !Yii::$app->request->get('advanced'); if($routine): ?><p class="wc-muted">8000 = 80% выручки. Минимальный интервал — 3600 секунд. Цель — свободные деньги на одном источнике; общий дневной лимит действует на всю стоянку.</p><?php endif ?>
<div class="wc-fields"><?php foreach($schema->columns as $field=>$column): if(in_array($field,$schema->primaryKey,true) || in_array($field,['revision','operation_id'],true) || ($routine && !in_array($field,['enabled','share_bps','interval_seconds','daily_limit_cr','target_cr'],true))) continue; $current=Yii::$app->request->post('values',[])[$field] ?? $row[$field]; ?>
<div class="wc-field"><label><?= Html::checkbox('selected['.$field.']',isset(Yii::$app->request->post('selected',[])[$field])) ?><?= Html::encode(WorldEntityCatalog::label($field)) ?> <small><?= Html::encode($field) ?></small></label>
<?php if(($target=EntityDisplay::target($entity,$field))): ?><?= EntityReferenceInput::widget(['table'=>$target,'name'=>'values['.$field.']','value'=>$current,'options'=>['aria-label'=>WorldEntityCatalog::label($field)]]) ?>
<?php elseif($column->type==='boolean' || in_array($field,['enabled'],true)): ?><?= Html::dropDownList('values['.$field.']',$current,['0'=>'Выключено','1'=>'Включено'],['class'=>'form-control','aria-label'=>WorldEntityCatalog::label($field)]) ?>
<?php elseif(str_ends_with($field,'_json') || $column->type==='text'): ?><?= Html::textarea('values['.$field.']',$current,['class'=>'form-control','rows'=>4,'aria-label'=>WorldEntityCatalog::label($field)]) ?>
<?php else: ?><?= Html::textInput('values['.$field.']',$current,['class'=>'form-control','aria-label'=>WorldEntityCatalog::label($field),'inputmode'=>in_array($column->type,['integer','bigint','decimal'],true)?'decimal':null]) ?><?php endif ?>
<?php if($column->allowNull): ?><small><?= Html::checkbox('nulls['.$field.']',$row[$field]===null) ?> Нет значения (NULL)</small><?php endif ?>
</div><?php endforeach ?></div>
<?php if($routine): ?><p style="margin-top:12px"><?= Html::a('Расширенное исправление состояния',['edit','entity'=>$entity,'key'=>$key,'advanced'=>1]) ?></p><?php endif ?>
<div class="form-group" style="margin-top:18px"><label for="wc-comment">Причина исправления</label><?= Html::textarea('comment',Yii::$app->request->post('comment',''),['id'=>'wc-comment','class'=>'form-control','required'=>true,'minlength'=>5,'maxlength'=>2000,'rows'=>3,'placeholder'=>'Что произошло и почему требуется ручное исправление']) ?></div>
<div class="wc-actions"><?= Html::submitButton('Проверить изменения',['class'=>'btn btn-primary']) ?><?= Html::a('Отмена',['view','entity'=>$entity,'key'=>$key],['class'=>'btn btn-default']) ?></div><?= Html::endForm() ?>
<?php endif ?></section></div>
