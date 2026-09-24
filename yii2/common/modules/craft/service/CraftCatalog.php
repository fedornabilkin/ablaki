<?php
namespace common\modules\craft\service;

use yii\web\UnprocessableEntityHttpException;

/** Portable catalog references stable codes, never database IDs. Import never deletes player data. */
class CraftCatalog
{
    private $store;
    public function __construct(CraftStorage $store) { $this->store=$store; }
    private function fail(string $message): void { throw new UnprocessableEntityHttpException($message); }
    public function export(): array
    {
        $s=$this->store; $categories=$s->rows('craft_category'); $items=$s->rows('craft_item'); $stations=$s->rows('craft_station'); $recipes=$s->rows('craft_recipe');
        $codes=static function($rows) { $map=[]; foreach($rows as $r) $map[$r['id']]=trim($r['code']); return $map; };
        $cat=$codes($categories); $it=$codes($items); $st=$codes($stations); $rc=$codes($recipes);
        $out=['version'=>1,'categories'=>[],'items'=>[],'stations'=>[],'recipes'=>[]];
        foreach($categories as $r) $out['categories'][]=['code'=>trim($r['code']),'name'=>trim($r['name']),'description'=>trim((string)$r['description'])];
        foreach($items as $r) {
            $entry=['code'=>trim($r['code']),'name'=>trim($r['name']),'description'=>trim((string)$r['description']),'category'=>$cat[$r['category_id']]];
            foreach(['kind','rarity','icon'] as $field) $entry[$field]=trim($r[$field]);
            $entry['storage_kind']=trim($r['storage_kind']??'none');
            foreach(['stack_size','destroyable','use_xp','gather_quantity','active'] as $field) $entry[$field]=(int)$r[$field];
            $out['items'][]=$entry;
        }
        foreach($stations as $r) $out['stations'][]=['code'=>trim($r['code']),'name'=>trim($r['name']),'item'=>$r['item_id']===null?null:$it[$r['item_id']],'active'=>(int)$r['active']];
        $ingredients=[]; foreach($s->rows('craft_recipe_item') as $r) $ingredients[$r['recipe_id']][]=['item'=>$it[$r['item_id']],'quantity'=>(int)$r['item_quantity']];
        $tools=[]; foreach($s->rows('craft_recipe_tool') as $r) $tools[$r['recipe_id']][]=$it[$r['item_id']];
        $deps=[]; foreach($s->rows('craft_dependency') as $r) $deps[$r['recipe_id']][]=$rc[$r['requires_id']];
        foreach($recipes as $r) {
            $entry=['code'=>trim($r['code']),'name'=>trim($r['name']),'description'=>trim((string)$r['description']),'category'=>$cat[$r['category_id']],'output'=>$it[$r['item_id']],'station'=>$r['station_id']===null?null:$st[$r['station_id']],'ingredients'=>$ingredients[$r['id']]??[],'tools'=>$tools[$r['id']]??[],'requires'=>$deps[$r['id']]??[]];
            foreach(['output_quantity','cost_credits','experience','min_level','active'] as $field) $entry[$field]=(int)$r[$field];
            $out['recipes'][]=$entry;
        }
        return $out;
    }
    public function validate(array $data): array
    {
        if (($data['version']??null)!==1) $this->fail('Поддерживается JSON каталога версии 1.');
        $sets=[];
        foreach(['categories','items','stations','recipes'] as $group) {
            if (!isset($data[$group])||!is_array($data[$group])||count($data[$group])>2000||array_keys($data[$group])!==array_keys(array_values($data[$group]))) $this->fail('Ожидается список '.$group.' (до 2000 записей).');
            $sets[$group]=[];
            foreach($data[$group] as $row) {
                if (!is_array($row)||!isset($row['code'],$row['name'])||!is_string($row['code'])||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$row['code'])||!is_string($row['name'])||trim($row['name'])===''||mb_strlen($row['name'])>50) $this->fail('Неверные код или имя в '.$group.'.');
                if (isset($sets[$group][$row['code']])) $this->fail('Повтор кода '.$row['code']);
                if (isset($row['description'])&&(!is_string($row['description'])||mb_strlen($row['description'])>5000)) $this->fail('Слишком длинное описание.');
                $sets[$group][$row['code']]=$row;
            }
        }
        $ref=function($group,$code) use($sets) { if (!is_string($code)||!isset($sets[$group][$code])) $this->fail('Неизвестная ссылка '.$group.': '.(is_scalar($code)?$code:'?')); };
        $number=function($row,$field,$min,$max) { if (!isset($row[$field])||!is_int($row[$field])||$row[$field]<$min||$row[$field]>$max) $this->fail('Неверное поле '.$field.' в '.$row['code']); };
        foreach($sets['items'] as $r) {
            $ref('categories',$r['category']??null);
            if (!in_array($r['kind']??null,['material','tool','equipment','consumable','station','product'],true)||!in_array($r['rarity']??null,['common','uncommon','rare','epic','legendary'],true)) $this->fail('Неверный тип/редкость '.$r['code']);
            if (!isset($r['icon'])||!is_string($r['icon'])||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$r['icon'])) $this->fail('Неверная иконка.');
            foreach(['stack_size'=>[1,10000],'destroyable'=>[0,1],'use_xp'=>[0,1000],'gather_quantity'=>[0,100],'active'=>[0,1]] as $field=>$bounds) $number($r,$field,$bounds[0],$bounds[1]);
            if ($r['use_xp']>0&&$r['kind']!=='consumable') $this->fail('Использование доступно только расходникам.');
            if ($r['gather_quantity']>0&&$r['kind']!=='material') $this->fail('Собирать можно только сырьё.');
            $storage=$r['storage_kind']??'none';
            if(!in_array($storage,['none','chest','elixir'],true)||($storage==='chest'&&$r['stack_size']!==1)||($storage==='elixir'&&$r['kind']!=='consumable'))$this->fail('Для сундука нужна стопка 1; эликсир должен быть расходником.');
        }
        foreach($sets['stations'] as $r) { if (($r['item']??null)!==null) $ref('items',$r['item']); $number($r,'active',0,1); }
        foreach($sets['recipes'] as $r) {
            $ref('categories',$r['category']??null); $ref('items',$r['output']??null);
            if (($r['station']??null)!==null) $ref('stations',$r['station']);
            foreach(['output_quantity'=>[1,1000],'cost_credits'=>[0,1000000],'experience'=>[0,1000],'min_level'=>[1,100],'active'=>[0,1]] as $field=>$bounds) $number($r,$field,$bounds[0],$bounds[1]);
            foreach(['ingredients','tools','requires'] as $field) if (!isset($r[$field])||!is_array($r[$field])||count($r[$field])>100) $this->fail('Неверный список '.$field);
            if ($r['active']&&!count($r['ingredients'])) $this->fail('Активный рецепт должен расходовать ингредиенты.');
            $seen=[];
            foreach($r['ingredients'] as $ing) {
                if (!is_array($ing)) $this->fail('Неверный ингредиент.');
                $ref('items',$ing['item']??null); $number($ing+['code'=>$r['code']],'quantity',1,10000);
                if (isset($seen[$ing['item']])) $this->fail('Повтор ингредиента.'); $seen[$ing['item']]=true;
            }
            foreach($r['tools'] as $code) $ref('items',$code);
            foreach($r['requires'] as $code) $ref('recipes',$code);
            if (count(array_unique($r['tools']))!==count($r['tools'])||count(array_unique($r['requires']))!==count($r['requires'])) $this->fail('Повтор инструмента/зависимости.');
        }
        $visiting=[];$done=[];
        $visit=function($code) use(&$visit,&$visiting,&$done,$sets) { if(isset($done[$code]))return; if(isset($visiting[$code]))$this->fail('Цикл зависимостей рецепта '.$code); $visiting[$code]=true; foreach($sets['recipes'][$code]['requires'] as $parent)$visit($parent); unset($visiting[$code]); $done[$code]=true; };
        foreach(array_keys($sets['recipes']) as $code) $visit($code);
        return $data;
    }
    public function preview(array $patch): array
    {
        if (($patch['version']??null)!==1) $this->fail('Поддерживается версия 1.');
        $data=$this->export(); $counts=[];
        foreach(['categories','items','stations','recipes'] as $group) {
            if (!isset($patch[$group])||!is_array($patch[$group])||count($patch[$group])>2000) $this->fail('Неверная группа '.$group);
            $map=[]; foreach($data[$group] as $r)$map[$r['code']]=$r;
            $seen=[]; foreach($patch[$group] as $r) { if(!is_array($r)||!isset($r['code'])||!is_string($r['code'])||isset($seen[$r['code']]))$this->fail('Неверный или повторный код.'); $seen[$r['code']]=true; if($group==='items')$r['storage_kind']=$r['storage_kind']??($map[$r['code']]['storage_kind']??'none'); $map[$r['code']]=$r; }
            $data[$group]=array_values($map); $counts[$group]=count($patch[$group]);
        }
        $this->validate($data);
        return ['catalog'=>$data,'counts'=>$counts,'digest'=>hash('sha256',json_encode($data))];
    }
    public function apply(array $patch,string $digest): array
    {
        return $this->store->db->transaction(function() use($patch,$digest) {
            $this->store->lock('craft_meta',['id'=>1]); $preview=$this->preview($patch);
            if (!hash_equals($preview['digest'],$digest)) throw new \yii\web\ConflictHttpException('Каталог изменился. Повторите предварительную проверку.');
            $data=$preview['catalog']; $s=$this->store; $maps=[];
            $upsert=function($table,$r) use($s) { $old=(new \yii\db\Query())->from($table)->where(['code'=>$r['code']])->one($s->db); if($old){$s->db->createCommand()->update($table,$r,['id'=>$old['id']])->execute();return (int)$old['id'];} return $s->insert($table,$r); };
            foreach($data['categories'] as $r) $maps['categories'][$r['code']]=$upsert('craft_category',['code'=>$r['code'],'name'=>$r['name'],'description'=>$r['description']??'']);
            foreach($data['items'] as $r) {
                $old=(new \yii\db\Query())->from('craft_item')->where(['code'=>$r['code']])->one($s->db);
                if($old&&($old['storage_kind']??'none')!==($r['storage_kind']??'none')&&(new \yii\db\Query())->from('craft_inventory')->where(['item_id'=>$old['id']])->andWhere(['>','item_quantity',0])->exists($s->db))$this->fail('Нельзя менять назначение хранилища у предметов в инвентарях. Создайте новый предмет.');
                $row=array_intersect_key($r,array_flip(['code','name','description','kind','rarity','icon','stack_size','destroyable','use_xp','gather_quantity','active','storage_kind']));$row['category_id']=$maps['categories'][$r['category']];$maps['items'][$r['code']]=$upsert('craft_item',$row);
            }
            foreach($data['stations'] as $r) $maps['stations'][$r['code']]=$upsert('craft_station',['code'=>$r['code'],'name'=>$r['name'],'active'=>$r['active'],'item_id'=>empty($r['item'])?null:$maps['items'][$r['item']]]);
            foreach($data['recipes'] as $r) {
                $row=array_intersect_key($r,array_flip(['code','name','description','output_quantity','cost_credits','experience','min_level','active']));
                $row+=['category_id'=>$maps['categories'][$r['category']],'item_id'=>$maps['items'][$r['output']],'station_id'=>empty($r['station'])?null:$maps['stations'][$r['station']]];
                $maps['recipes'][$r['code']]=$upsert('craft_recipe',$row);
            }
            foreach($data['recipes'] as $r) {
                $id=$maps['recipes'][$r['code']];
                foreach(['craft_recipe_item','craft_recipe_tool','craft_dependency'] as $table)$s->db->createCommand()->delete($table,['recipe_id'=>$id])->execute();
                foreach($r['ingredients'] as $ing)$s->insert('craft_recipe_item',['recipe_id'=>$id,'item_id'=>$maps['items'][$ing['item']],'item_quantity'=>$ing['quantity']]);
                foreach($r['tools'] as $code)$s->insert('craft_recipe_tool',['recipe_id'=>$id,'item_id'=>$maps['items'][$code]]);
                foreach($r['requires'] as $code)$s->db->createCommand()->insert('craft_dependency',['recipe_id'=>$id,'requires_id'=>$maps['recipes'][$code]])->execute();
            }
            $s->db->createCommand()->update('craft_meta',['revision'=>new \yii\db\Expression('[[revision]]+1')],['id'=>1])->execute();
            return $preview['counts'];
        });
    }
}
