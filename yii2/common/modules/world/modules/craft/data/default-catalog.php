<?php
// Versioned initial content. Import uses stable codes and never replaces inventories.
$data=['version'=>1,'categories'=>[],'items'=>[],'stations'=>[],'recipes'=>[]];
foreach (['wood'=>'Деревообработка','stone'=>'Каменное дело','metal'=>'Кузнечное дело','textile'=>'Ткачество','alchemy'=>'Алхимия'] as $code=>$name) $data['categories'][]=['code'=>'classic-'.$code,'name'=>$name,'description'=>'Классический крафт: '.$name];
$item=function($code,$name,$category,$kind='material',$gather=0,$xp=0) use(&$data) {
    $icons=['material'=>'cubes','tool'=>'hammer','station'=>'industry','equipment'=>'shield-halved','consumable'=>'flask','product'=>'cube'];
    $data['items'][]=['code'=>'classic-'.$code,'name'=>$name,'description'=>$kind==='consumable'?'При использовании: +'.$xp.' опыта категории.':'Материал или изделие мастерской.','category'=>'classic-'.$category,'kind'=>$kind,'rarity'=>in_array($kind,['equipment','consumable'],true)?'uncommon':'common','icon'=>$icons[$kind],'stack_size'=>in_array($kind,['station','tool','equipment'],true)?10:100,'destroyable'=>1,'use_xp'=>$xp,'gather_quantity'=>$gather,'active'=>1];
};
foreach ([['log','Бревно','wood',20],['stone','Камень','stone',20],['clay','Глина','stone',10],['sand','Песок','stone',10],['ore','Железная руда','metal',12],['coal','Уголь','metal',12],['fibre','Волокно','textile',20],['hide','Шкура','textile',8],['herb','Лечебная трава','alchemy',12],['water','Вода','alchemy',12]] as $r) $item($r[0],$r[1],$r[2],'material',$r[3]);
$recipe=function($code,$name,$category,$kind,$ingredients,$requires=[],$station=null,$tools=[],$cost=0,$level=1,$out=1,$xp=20,$useXp=0) use(&$data,$item) {
    $item($code,$name,$category,$kind,0,$useXp);
    $prefix=static function($value){return 'classic-'.$value;};
    $ing=[];foreach($ingredients as $key=>$qty)$ing[]=['item'=>$prefix($key),'quantity'=>$qty];
    $data['recipes'][]=['code'=>$prefix($code),'name'=>'Создать: '.$name,'description'=>'Изготовление в мастерской. Инструменты и станция сохраняются.','category'=>$prefix($category),'output'=>$prefix($code),'output_quantity'=>$out,'cost_credits'=>$cost,'experience'=>$xp,'min_level'=>$level,'station'=>$station?$prefix($station):null,'active'=>1,'ingredients'=>$ing,'tools'=>array_map($prefix,$tools),'requires'=>array_map($prefix,$requires)];
};
$recipe('plank','Доска','wood','material',['log'=>1],[],null,[],0,1,2);
$recipe('stick','Палка','wood','material',['plank'=>1],['plank'],null,[],0,1,3);
$recipe('rope','Верёвка','textile','material',['fibre'=>3]);
$recipe('hammer','Каменный молоток','stone','tool',['stone'=>2,'stick'=>1],['stick']);
$recipe('bench','Верстак','wood','station',['plank'=>6,'stone'=>4,'rope'=>2],['plank','rope','hammer'],null,['hammer']);
$recipe('brick','Кирпич','stone','material',['clay'=>2,'sand'=>1],['hammer'],null,['hammer'],0,1,2);
$recipe('furnace','Плавильня','stone','station',['brick'=>8,'stone'=>8],['brick','bench'],'bench',['hammer']);
$recipe('ingot','Железный слиток','metal','material',['ore'=>2,'coal'=>1],['furnace'],'furnace');
$recipe('anvil','Наковальня','metal','station',['ingot'=>5,'stone'=>4],['ingot'],'bench',['hammer']);
$recipe('alchemy','Алхимический стол','alchemy','station',['plank'=>6,'brick'=>4,'ingot'=>1],['bench','ingot'],'bench',['hammer']);
$recipe('nails','Гвозди','metal','material',['ingot'=>1],['anvil'],'anvil',['hammer'],0,1,8);
$recipe('saw','Пила','metal','tool',['ingot'=>2,'stick'=>2],['nails'],'anvil',['hammer']);
$recipe('axe','Топор','metal','tool',['ingot'=>2,'stick'=>2,'rope'=>1],['anvil'],'anvil',['hammer']);
$recipe('pickaxe','Кирка','metal','tool',['ingot'=>3,'stick'=>2],['anvil'],'anvil',['hammer']);
$recipe('chest','Сундук','wood','product',['plank'=>8,'nails'=>8],['nails'],'bench',['saw'],1);
$recipe('chair','Стул','wood','product',['plank'=>4,'stick'=>4,'nails'=>4],['bench','nails'],'bench',['hammer']);
$recipe('table','Стол','wood','product',['plank'=>6,'stick'=>4,'nails'=>4],['chair'],'bench',['saw'],1);
$recipe('ladder','Лестница','wood','product',['stick'=>8,'rope'=>2],['bench'],'bench');
$recipe('door','Дверь','wood','product',['plank'=>6,'nails'=>6,'ingot'=>1],['chest'],'bench',['saw'],1,2);
$recipe('cloth','Ткань','textile','material',['fibre'=>4],['rope'],'bench',[],0,1,2);
$recipe('leather','Кожа','textile','material',['hide'=>2,'water'=>1],['cloth'],'bench');
$recipe('bag','Сумка','textile','product',['cloth'=>4,'rope'=>2,'leather'=>1],['leather'],'bench');
$recipe('boots','Кожаные сапоги','textile','equipment',['leather'=>3,'cloth'=>2,'rope'=>1],['bag'],'bench',[],1,2);
$recipe('helmet','Железный шлем','metal','equipment',['ingot'=>3,'leather'=>1],['anvil','leather'],'anvil',['hammer'],2,2);
$recipe('sword','Железный меч','metal','equipment',['ingot'=>4,'stick'=>1,'leather'=>1],['helmet'],'anvil',['hammer'],3,2);
$recipe('shield','Деревянный щит','wood','equipment',['plank'=>4,'ingot'=>2,'leather'=>1],['chest','leather'],'bench',['saw'],2,2);
$recipe('glass','Стекло','stone','material',['sand'=>3,'coal'=>1],['furnace'],'furnace',[],0,1,2);
$recipe('bottle','Бутылка','stone','product',['glass'=>2],['glass'],'furnace',[],0,1,2);
$recipe('extract','Травяной экстракт','alchemy','material',['herb'=>3,'water'=>1],['alchemy'],'alchemy');
$recipe('potion','Настой мастерства','alchemy','consumable',['extract'=>1,'bottle'=>1,'water'=>1],['extract','bottle'],'alchemy',[],1,1,1,20,30);
$recipe('ink','Чернила','alchemy','material',['coal'=>1,'extract'=>1,'water'=>1],['extract'],'alchemy',[],0,1,2);
$recipe('paper','Бумага','wood','material',['plank'=>1,'water'=>2],['bench'],'bench',[],0,1,3);
$recipe('manual','Учебник ремесленника','wood','consumable',['paper'=>6,'ink'=>2,'leather'=>1],['paper','ink','leather'],'bench',[],2,2,1,30,50);
$recipe('lantern','Фонарь','metal','product',['ingot'=>2,'glass'=>2,'coal'=>1],['glass','anvil'],'anvil',['hammer'],1,2);
$recipe('space-elixir','Эликсир пространства','alchemy','consumable',['extract'=>2,'bottle'=>1,'water'=>2],['extract','bottle'],'alchemy');
foreach($data['items'] as &$entry) {
    $entry['storage_kind']=$entry['code']==='classic-chest'?'chest':($entry['code']==='classic-space-elixir'?'elixir':'none');
    if($entry['storage_kind']==='chest'){$entry['stack_size']=1;$entry['icon']='box';$entry['description']='Хранит предметы в отдельных слотах. Износ не удаляет содержимое.';}
    if($entry['storage_kind']==='elixir')$entry['description']='Временно открывает дополнительные слоты инвентаря.';
}unset($entry);
foreach (['bench'=>'Верстак','furnace'=>'Плавильня','anvil'=>'Наковальня','alchemy'=>'Алхимический стол'] as $code=>$name) $data['stations'][]=['code'=>'classic-'.$code,'name'=>$name,'item'=>'classic-'.$code,'active'=>1];
return $data;
