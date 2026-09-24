<?php use yii\helpers\Html; $this->title='Редактор каталога';
$labels=['code'=>'Код','name'=>'Название','description'=>'Описание','category'=>'Категория','kind'=>'Тип','rarity'=>'Редкость','icon'=>'Иконка Font Awesome','stack_size'=>'Предметов в слоте','destroyable'=>'Можно удалять (0/1)','use_xp'=>'Опыт категории при использовании расходника','gather_quantity'=>'Сырья в ежедневном наборе (0 — не выдаётся)','active'=>'Активен (0/1)','item'=>'Предмет станции (не расходуется)','output'=>'Результат','output_quantity'=>'Количество результата','cost_credits'=>'Цена одного крафта, Cr','experience'=>'Опыт за один крафт','min_level'=>'Минимальный уровень категории','station'=>'Станция','ingredients'=>'Ингредиенты: код:количество, по одному на строку','tools'=>'Инструменты (не расходуются), коды через запятую','requires'=>'Сначала освоить рецепты, коды через запятую']; ?>
<?php $labels['storage_kind']='Назначение хранилища'; ?>
<h1><?= Html::encode($this->title) ?></h1>
<?= Html::beginForm('','post') ?>
<?php foreach($record as $key=>$value): $id='craft-'.$key; ?>
<div class="form-group"><label for="<?= Html::encode($id) ?>"><?= Html::encode($labels[$key]??$key) ?></label>
<?php $options=['class'=>'form-control','id'=>$id]; $name='record['.$key.']';
if(in_array($key,['category','output','item','station'],true)) {
 $source=$key==='category'?'categories':($key==='station'?'stations':'items');$choices=[''=>'—'];foreach($catalog[$source] as $r)$choices[$r['code']]=$r['name'].' ('.$r['code'].')';echo Html::dropDownList($name,$value,$choices,$options);
} elseif($key==='kind')echo Html::dropDownList($name,$value,['material'=>'Материал','tool'=>'Инструмент','equipment'=>'Снаряжение','consumable'=>'Расходник','station'=>'Станция','product'=>'Изделие'],$options);
elseif($key==='rarity')echo Html::dropDownList($name,$value,array_combine(['common','uncommon','rare','epic','legendary'],['Обычный','Необычный','Редкий','Эпический','Легендарный']),$options);
elseif($key==='storage_kind')echo Html::dropDownList($name,$value,['none'=>'Обычный предмет','chest'=>'Сундук (стопка 1)','elixir'=>'Эликсир слотов'],$options);
elseif(is_array($value)){if($key==='ingredients')$value=array_map(static function($r){return $r['item'].':'.$r['quantity'];},$value);echo Html::textarea($name,implode("\n",$value),$options+['rows'=>5]);}
elseif($key==='description')echo Html::textarea($name,$value,$options+['rows'=>3]);
else echo Html::input(is_int($value)?'number':'text',$name,$value,$options+($key==='code'&&$existing?['readonly'=>true]:[])); ?>
</div><?php endforeach ?>
<?= Html::submitButton('Проверить и сохранить',['class'=>'btn btn-primary']) ?> <?= Html::a('Назад',['index']) ?><?= Html::endForm() ?>
