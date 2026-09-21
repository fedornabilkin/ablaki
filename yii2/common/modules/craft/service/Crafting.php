<?php
namespace common\modules\craft\service;

use yii\db\Query;
use yii\web\ConflictHttpException;
use yii\web\UnprocessableEntityHttpException;

class Crafting
{
    private $s;
    public function __construct(CraftStorage $store) { $this->s=$store; }
    private function one(string $table,array $where): ?array { $row=(new Query())->from($table)->where($where)->one($this->s->db); return $row?:null; }
    private function item(int $id,bool $active=true): array { $item=$this->one('craft_item',$active?['id'=>$id,'active'=>1]:['id'=>$id]); if(!$item)throw new ConflictHttpException('Предмет недоступен.'); return $item; }
    private function xp(int $user,int $category,int $add): void
    {
        $where=['user_id'=>$user,'category_id'=>$category]; $row=$this->one('craft_skill',$where);
        if($row) {
            $next=min(9900,(int)$row['experience']+$add);
            if($next!==(int)$row['experience']&&$this->s->db->createCommand()->update('craft_skill',['experience'=>$next],$where)->execute()!==1)throw new \RuntimeException('Skill write failed.');
        } elseif($this->s->db->createCommand()->insert('craft_skill',$where+['experience'=>min(9900,$add)])->execute()!==1)throw new \RuntimeException('Skill write failed.');
    }
    public function requirements(int $user,array $recipe): array
    {
        $reasons=[];
        $skill=$this->one('craft_skill',['user_id'=>$user,'category_id'=>$recipe['category_id']]);
        if(1+intdiv((int)($skill['experience']??0),100)<(int)$recipe['min_level'])$reasons[]='Нужен уровень '.$recipe['min_level'];
        foreach($this->s->rows('craft_dependency',['recipe_id'=>$recipe['id']]) as $dep) if(!$this->one('craft_known',['user_id'=>$user,'recipe_id'=>$dep['requires_id']])) {
            $parent=$this->one('craft_recipe',['id'=>$dep['requires_id']]);
            $output=$parent?$this->one('craft_item',['id'=>$parent['item_id']]):null;
            $reasons[]='Сначала создайте: '.trim($output['name']??preg_replace('/^Создать:\s*/u','',$parent['name']??'рецепт #'.$dep['requires_id']));
        }
        return $reasons;
    }
    private function event(int $user,string $action,int $qty,array $extra=[]): void
    {
        $this->s->insert('craft_event',$extra+['user_id'=>$user,'action'=>$action,'quantity'=>$qty,'credit_change'=>0,'created_at'=>time()]);
    }
    public function command(int $user,string $key,string $action,array $payload): array
    {
        if($user<1||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new UnprocessableEntityHttpException('Нужен корректный ключ команды.');
        if(!in_array($action,['craft','starter','gather','discard','use'],true))throw new UnprocessableEntityHttpException('Неизвестная операция.');
        $qty=$payload['quantity']??1; $id=$payload['id']??0;
        $maxQty=in_array($action,['use','discard'],true)?10000:100;
        if(!is_int($qty)||$qty<1||$qty>$maxQty||!is_int($id)||$id<0)throw new UnprocessableEntityHttpException('Количество должно быть целым от 1 до '.$maxQty.'.');
        $slotId=$payload['slot_id']??null;
        if($slotId!==null&&(!is_int($slotId)||$slotId<1||!in_array($action,['use','discard'],true)))throw new UnprocessableEntityHttpException('Некорректный слот инвентаря.');
        $identity=[$action,$id,$qty];if($slotId!==null)$identity[]=$slotId;
        $fingerprint=hash('sha256',json_encode($identity));
        $result=$this->s->db->transaction(function() use($user,$key,$action,$id,$qty,$slotId,$fingerprint) {
            // Catalog imports and player commands use the same short, deterministic lock order.
            $meta=$this->s->lock('craft_meta',['id'=>1]); $person=$this->s->lock('persone',['user_id'=>$user]);
            $old=$this->one('craft_command',['user_id'=>$user,'request_key'=>$key]);
            if($old) { if(!hash_equals($old['fingerprint'],$fingerprint))throw new ConflictHttpException('Этот ключ уже использован для другой команды.'); return json_decode($old['result'],true)+['replayed'=>true]; }
            $recent=(new Query())->from('craft_command')->where(['user_id'=>$user])->andWhere(['>=','created_at',time()-60])->count('*',$this->s->db);
            if((int)$recent>=30)throw new \yii\web\TooManyRequestsHttpException('Слишком много операций. Попробуйте через минуту.');
            if($action==='craft')$message=$this->craft($user,$person,$id,$qty,(int)($meta['charge_credits']??0)===1);
            elseif($action==='starter'||$action==='gather')$message=$this->supplies($user,$action);
            else {
                $item=$this->item($id,$action!=='discard');
                if($action==='discard'&&!(int)$item['destroyable'])throw new ConflictHttpException('Этот предмет нельзя удалить.');
                if($action==='use'&&(trim($item['kind'])!=='consumable'||(int)$item['use_xp']<1))throw new ConflictHttpException('Этот предмет нельзя использовать.');
                $this->s->move($user,$item,-$qty,$slotId);
                if($action==='use')$this->xp($user,(int)$item['category_id'],(int)$item['use_xp']*$qty);
                $this->event($user,$action,$qty,['item_id'=>$id]); $message=($action==='use'?'Использовано: ':'Удалено: ').trim($item['name']).' × '.$qty;
            }
            $result=['message'=>$message];
            $this->s->insert('craft_command',['user_id'=>$user,'request_key'=>$key,'fingerprint'=>$fingerprint,'result'=>json_encode($result,JSON_UNESCAPED_UNICODE),'created_at'=>time()]);
            return $result+['replayed'=>false];
        });
        return $result+['state'=>$this->state($user)];
    }
    private function supplies(int $user,string $action): string
    {
        $query=(new Query())->from('craft_event')->where(['user_id'=>$user,'action'=>$action]);
        if($action==='gather')$query->andWhere(['>=','created_at',(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Moscow')))->getTimestamp()]);
        if($query->exists($this->s->db))throw new ConflictHttpException($action==='starter'?'Стартовый набор уже получен.':'Сырьё на сегодня уже собрано.');
        $items=(new Query())->from('craft_item')->where(['active'=>1])->andWhere(['>','gather_quantity',0])->all($this->s->db);
        if(!$items)throw new ConflictHttpException('Источники сырья пока не настроены.');
        $total=0; foreach($items as $item) { $amount=(int)$item['gather_quantity']*($action==='starter'?3:1); $this->s->move($user,$item,$amount);$total+=$amount; }
        $this->event($user,$action,$total);
        return $action==='starter'?'Стартовые материалы получены.':'Сырьё на сегодня собрано.';
    }
    private function craft(int $user,array $person,int $id,int $qty,bool $chargeCredits): string
    {
        $recipe=$this->one('craft_recipe',['id'=>$id,'active'=>1]); if(!$recipe)throw new ConflictHttpException('Рецепт недоступен.');
        foreach (['output_quantity'=>[1,1000],'cost_credits'=>[0,1000000],'experience'=>[0,1000],'min_level'=>[1,100]] as $field=>$bounds) {
            if ((int)$recipe[$field]<$bounds[0]||(int)$recipe[$field]>$bounds[1]) throw new ConflictHttpException('Рецепт требует проверки администратором.');
        }
        $reasons=$this->requirements($user,$recipe); if($reasons)throw new ConflictHttpException(implode('. ',$reasons));
        $output=$this->item((int)$recipe['item_id']);
        $ingredients=$this->s->rows('craft_recipe_item',['recipe_id'=>$id]); if(!$ingredients)throw new ConflictHttpException('У рецепта нет ингредиентов.');
        foreach ($ingredients as $ing) if ((int)$ing['item_quantity']<1||(int)$ing['item_quantity']>10000) throw new ConflictHttpException('Некорректный ингредиент рецепта.');
        $required=[]; $items=[];
        foreach($ingredients as $ing) { $item=$this->item((int)$ing['item_id']); $items[$item['id']]=$item; $required[$item['id']]=($required[$item['id']]??0)+(int)$ing['item_quantity']*$qty; }
        $reserve=[];
        foreach($this->s->rows('craft_recipe_tool',['recipe_id'=>$id]) as $tool)$reserve[(int)$tool['item_id']]=1;
        if($recipe['station_id']!==null) {
            $station=$this->one('craft_station',['id'=>$recipe['station_id'],'active'=>1]); if(!$station)throw new ConflictHttpException('Станция недоступна.');
            if($station['item_id']!==null)$reserve[(int)$station['item_id']]=1;
        }
        $quantities=$this->s->quantities($user);
        foreach($reserve as $tool=>$count) { $items[$tool]=$this->item($tool); $required[$tool]=($required[$tool]??0)+$count; }
        foreach($required as $item=>$amount) if(($quantities[$item]??0)<$amount)throw new ConflictHttpException('Не хватает: '.trim($items[$item]['name']));
        $cost=$chargeCredits?(int)$recipe['cost_credits']*$qty:0;
        if($cost>0&&(float)$person['credit']<$cost)throw new ConflictHttpException('Не хватает кредитов.');
        foreach($ingredients as $ing)$this->s->move($user,$items[$ing['item_id']],-(int)$ing['item_quantity']*$qty);
        $this->s->move($user,$output,(int)$recipe['output_quantity']*$qty);
        if($cost) {
            (new \common\services\user\CreditLedger($this->s->db))->change($user,-$cost,'craft','Craft #'.$id.' x '.$qty);
        }
        $where=['user_id'=>$user,'recipe_id'=>$id]; $known=$this->one('craft_known',$where);
        $write=$known?$this->s->db->createCommand()->update('craft_known',['quantity'=>(int)$known['quantity']+$qty],$where):$this->s->db->createCommand()->insert('craft_known',$where+['quantity'=>$qty]);
        if($write->execute()!==1)throw new \RuntimeException('Recipe progress write failed.');
        $this->xp($user,(int)$recipe['category_id'],(int)$recipe['experience']*$qty);
        $this->s->insert('craft_history',['user_id'=>$user,'recipe_id'=>$id,'item_id'=>$output['id'],'created_at'=>time()]);
        $this->event($user,'craft',(int)$recipe['output_quantity']*$qty,['recipe_id'=>$id,'item_id'=>$output['id'],'credit_change'=>-$cost]);
        return 'Создано: '.trim($output['name']).' × '.((int)$recipe['output_quantity']*$qty);
    }
    public function state(int $user): array
    {
        $s=$this->s; $person=$this->one('persone',['user_id'=>$user]); if(!$person)throw new ConflictHttpException('Профиль недоступен.');
        $items=[]; foreach($s->rows('craft_item') as $r) { foreach(['name','code','kind','rarity','icon','description'] as $field)$r[$field]=trim((string)$r[$field]); foreach(['id','category_id','stack_size','destroyable','use_xp','gather_quantity','active'] as $field)$r[$field]=(int)$r[$field]; $items[]=$r; }
        $known=[]; foreach($s->rows('craft_known',['user_id'=>$user]) as $r)$known[(int)$r['recipe_id']]=(int)$r['quantity'];
        $recipes=[]; foreach($s->rows('craft_recipe',['active'=>1]) as $r) {
            foreach(['id','category_id','item_id','output_quantity','cost_credits','experience','min_level'] as $field)$r[$field]=(int)$r[$field];
            foreach(['name','code','description'] as $field)$r[$field]=trim((string)$r[$field]);
            $r['station_id']=$r['station_id']===null?null:(int)$r['station_id'];
            $r['ingredients']=array_map(static function($v){return ['item_id'=>(int)$v['item_id'],'quantity'=>(int)$v['item_quantity']];},$s->rows('craft_recipe_item',['recipe_id'=>$r['id']]));
            $r['tools']=array_map('intval',array_column($s->rows('craft_recipe_tool',['recipe_id'=>$r['id']]),'item_id'));
            $r['requires']=array_map('intval',array_column($s->rows('craft_dependency',['recipe_id'=>$r['id']]),'requires_id'));
            $r['locked_reasons']=$this->requirements($user,$r); $r['crafted']=$known[$r['id']]??0; $recipes[]=$r;
        }
        $categories=array_map(static function($r){return ['id'=>(int)$r['id'],'name'=>trim($r['name']),'code'=>trim($r['code']),'description'=>trim((string)$r['description'])];},$s->rows('craft_category'));
        $stations=array_map(static function($r){return ['id'=>(int)$r['id'],'name'=>trim($r['name']),'item_id'=>$r['item_id']===null?null:(int)$r['item_id']];},$s->rows('craft_station',['active'=>1]));
        $skills=array_map(static function($r){$xp=(int)$r['experience'];return ['category_id'=>(int)$r['category_id'],'experience'=>$xp,'level'=>1+intdiv($xp,100)];},$s->rows('craft_skill',['user_id'=>$user]));
        $inventory=[]; foreach($s->quantities($user) as $id=>$qty)if($qty>0)$inventory[]=['item_id'=>$id,'quantity'=>$qty];
        $slots=[];foreach($s->rows('craft_inventory',['user_id'=>$user]) as $slot)if($slot['item_id']!==null&&(int)$slot['item_quantity']>0)$slots[]=['id'=>(int)$slot['id'],'item_id'=>(int)$slot['item_id'],'quantity'=>(int)$slot['item_quantity']];
        $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        $settings=['charge_credits'=>(new CraftSettings($s))->chargeCredits()];
        return $settings+['items'=>$items,'categories'=>$categories,'recipes'=>$recipes,'stations'=>$stations,'skills'=>$skills,'inventory'=>$inventory,'inventory_slots'=>$slots,'credit'=>(float)$person['credit'],'starter_available'=>!$this->one('craft_event',['user_id'=>$user,'action'=>'starter']),'gather_available'=>!(new Query())->from('craft_event')->where(['user_id'=>$user,'action'=>'gather'])->andWhere(['>=','created_at',$today])->exists($s->db),'slot_limit'=>CraftStorage::SLOT_LIMIT,'slots_used'=>count($slots)];
    }
}
