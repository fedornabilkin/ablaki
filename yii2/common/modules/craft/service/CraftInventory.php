<?php
namespace common\modules\craft\service;

use yii\db\Query;
use yii\web\ConflictHttpException;

/** Owner/catalog locks are held by commands; state uses the same non-destructive layout projection. */
class CraftInventory
{
    public const LIMIT=50;
    public const BASE=20;
    private $s;
    public function __construct(CraftStorage $store) { $this->s=$store; }
    private function one(string $table,array $where): ?array { return (new Query())->from($table)->where($where)->one($this->s->db)?:null; }
    public function settings(): array { return (new CraftSettings($this->s))->inventory(); }
    public function state(int $user): array
    {
        $capacity=$this->capacity($user);$slots=[];$containers=[];
        $chestItems=array_flip((new Query())->select('id')->from('craft_item')->where(['storage_kind'=>'chest'])->column($this->s->db));
        $dto=static function($row){return ['id'=>(int)$row['id'],'item_id'=>(int)$row['item_id'],'quantity'=>(int)$row['item_quantity'],'position'=>(int)$row['slot']];};
        foreach($this->layout($user) as $slot) {
            $slots[]=$dto($slot)+['active'=>(int)$slot['slot']<=$capacity['active_slots']];
            if(!isset($chestItems[$slot['item_id']]))continue;
            $container=$this->container($user,(int)$slot['id']);$contents=[];
            foreach($this->s->rows('craft_inventory',['user_id'=>$user,'container_id'=>$slot['id']]) as $row)if($row['item_id']&&(int)$row['item_quantity']>0)$contents[]=$dto($row);
            $containers[]=['id'=>(int)$slot['id'],'capacity'=>(int)$container['capacity'],'durability'=>(int)$container['durability'],'max_durability'=>(int)$container['max_durability'],'slots'=>$contents];
        }
        return $capacity+['slot_limit'=>self::LIMIT,'slots_used'=>count($slots),'inventory_slots'=>$slots,'containers'=>$containers,'inventory_settings'=>$this->settings(),'server_time'=>time()];
    }
    public function capacity(int $user): array
    {
        $row=$this->one('craft_capacity',['user_id'=>$user]);
        $permanent=max(self::BASE,min(self::LIMIT,(int)($row['permanent_slots']??self::BASE)));
        $leases=$this->s->rows('craft_slot_lease',['user_id'=>$user]);$temporary=0;$expires=null;
        foreach($leases as $lease)if((int)$lease['expires_at']>time()){$temporary+=(int)$lease['slots'];$expires=$expires===null?(int)$lease['expires_at']:min($expires,(int)$lease['expires_at']);}
        return ['permanent_slots'=>$permanent,'active_slots'=>min(self::LIMIT,$permanent+$temporary),'slots_expire_at'=>$expires];
    }
    /** One-time conversion retains IDs and every item, even beyond the visible 50 cells. */
    public function initialize(int $user): void
    {
        if($this->one('craft_capacity',['user_id'=>$user]))return;
        $position=0;
        foreach($this->s->rows('craft_inventory',['user_id'=>$user,'container_id'=>null]) as $slot) {
            if(!$slot['item_id']||(int)$slot['item_quantity']<1)continue;
            $item=$this->one('craft_item',['id'=>$slot['item_id']]);$chest=($item['storage_kind']??'none')==='chest';
            $this->write((int)$slot['id'],['slot'=>++$position,'item_quantity'=>$chest?1:(int)$slot['item_quantity']]);
            if($chest)for($i=1;$i<(int)$slot['item_quantity'];$i++)$this->s->insert('craft_inventory',['user_id'=>$user,'item_id'=>$slot['item_id'],'item_quantity'=>1,'slot'=>++$position]);
        }
        if($this->s->db->createCommand()->insert('craft_capacity',['user_id'=>$user,'permanent_slots'=>self::BASE])->execute()!==1)throw new \RuntimeException('Capacity initialization failed.');
    }
    public function layout(int $user): array
    {
        $active=$this->capacity($user)['active_slots'];$occupied=[];$held=[];
        foreach($this->s->rows('craft_inventory',['user_id'=>$user,'container_id'=>null]) as $slot) {
            if(!$slot['item_id']||(int)$slot['item_quantity']<1)continue;
            $index=(int)$slot['slot'];
            if($index>0&&$index<=$active&&!isset($occupied[$index]))$occupied[$index]=$slot;else $held[]=$slot;
        }
        foreach($held as $slot) {
            $free=null;for($i=1;$i<=$active;$i++)if(!isset($occupied[$i])){$free=$i;break;}
            $index=$free??max($active+1,(int)$slot['slot']);while(isset($occupied[$index]))$index++;
            $slot['slot']=$index;$occupied[$index]=$slot;
        }
        ksort($occupied);return array_values($occupied);
    }
    public function synchronize(int $user): void
    {
        $this->initialize($user);
        foreach($this->layout($user) as $slot)$this->s->db->createCommand()->update('craft_inventory',['slot'=>$slot['slot']],['id'=>$slot['id']])->execute();
    }
    public function write(int $id,array $values): void
    {
        $changed=$this->s->db->createCommand()->update('craft_inventory',$values,['id'=>$id])->execute();
        // MySQL reports changed rows, so an already normalized slot may return zero.
        if($changed!==1&&($changed!==0||!(new Query())->from('craft_inventory')->where(['id'=>$id]+$values)->exists($this->s->db)))throw new \RuntimeException('Inventory write failed.');
    }
    public function container(int $user,int $id): array
    {
        $slot=$this->one('craft_inventory',['id'=>$id,'user_id'=>$user,'container_id'=>null]);
        $item=$slot?$this->one('craft_item',['id'=>$slot['item_id']]):null;
        if(!$slot||(int)$slot['item_quantity']!==1||($item['storage_kind']??'none')!=='chest')throw new ConflictHttpException('Выберите свой сундук.');
        $stored=$this->one('craft_container',['id'=>$id,'user_id'=>$user]);
        if($stored)return $stored;
        $settings=$this->settings();return ['id'=>$id,'user_id'=>$user,'capacity'=>$settings['chest_slots'],'durability'=>$settings['chest_durability'],'max_durability'=>$settings['chest_durability']];
    }
    public function nonempty(int $id): bool
    {
        return (new Query())->from('craft_inventory')->where(['container_id'=>$id])->andWhere(['>','item_quantity',0])->exists($this->s->db);
    }
    public function buy(int $user,int $quantity,int $quotedPrice): int
    {
        $price=$this->settings()['slot_price'];if($quotedPrice!==$price)throw new ConflictHttpException('Цена слота изменилась. Обновите мастерскую.');
        $capacity=$this->capacity($user);$next=$capacity['permanent_slots']+$quantity;
        if($next>self::LIMIT)throw new ConflictHttpException('Все постоянные слоты уже открыты.');
        $cost=$price*$quantity;
        if($cost) {
            $this->requireTransactionalBalance();
            (new \common\services\user\CreditLedger($this->s->db))->change($user,-$cost,'craft_slots','Покупка слотов инвентаря: '.$quantity);
        }
        if($this->s->db->createCommand()->update('craft_capacity',['permanent_slots'=>$next],['user_id'=>$user])->execute()!==1)throw new \RuntimeException('Capacity write failed.');
        return $cost;
    }
    public function requireTransactionalBalance(): void
    {
        if($this->s->db->driverName!=='mysql')return;
        $tables=['persone','history_balance','craft_inventory','craft_capacity','craft_command','craft_event'];
        $rows=(new Query())->select(['TABLE_NAME','ENGINE'])->from('information_schema.TABLES')->where(['TABLE_SCHEMA'=>new \yii\db\Expression('DATABASE()'),'TABLE_NAME'=>$tables])->all($this->s->db);
        if(count($rows)!==count($tables))throw new ConflictHttpException('Для оплаты требуется настройка таблиц баланса администратором.');
        foreach($rows as $row)if(strtolower($row['ENGINE'])!=='innodb')throw new ConflictHttpException('Для оплаты требуется транзакционный журнал баланса. Обратитесь к администратору.');
    }
    public function unlock(int $user,int $quantity): void
    {
        $settings=$this->settings();$slots=$settings['elixir_slots']*$quantity;
        if($this->capacity($user)['active_slots']+$slots>self::LIMIT)throw new ConflictHttpException('Для этого количества эликсира недостаточно закрытых слотов.');
        $this->s->insert('craft_slot_lease',['user_id'=>$user,'slots'=>$slots,'expires_at'=>time()+$settings['elixir_days']*86400]);
    }
    /** Target container 0 means backpack; target position is stable, including empty cells. */
    public function transfer(int $user,int $sourceId,int $containerId,int $position,int $quantity): int
    {
        $source=$this->one('craft_inventory',['id'=>$sourceId,'user_id'=>$user]);
        if(!$source||!$source['item_id']||(int)$source['item_quantity']<$quantity)throw new ConflictHttpException('Предмет в исходном слоте изменился.');
        $item=$this->one('craft_item',['id'=>$source['item_id']]);
        $container=$containerId?$this->container($user,$containerId):null;
        $limit=$container?(int)$container['capacity']:$this->capacity($user)['active_slots'];
        if($position<1||$position>$limit)throw new ConflictHttpException('Слот недоступен.');
        if($container&&($item['storage_kind']??'none')==='chest')throw new ConflictHttpException('Сундук нельзя помещать в другой сундук.');
        if($container&&(int)$container['durability']<1)throw new ConflictHttpException('Сундук изношен. Предметы можно только забрать.');
        $where=['user_id'=>$user,'container_id'=>$containerId?:null,'slot'=>$position];
        $target=(new Query())->from('craft_inventory')->where($where)->andWhere(['>','item_quantity',0])->one($this->s->db);
        if($target&&(int)$target['id']===$sourceId)throw new ConflictHttpException('Предмет уже в этом слоте.');
        if($target&&(int)$target['item_id']!==(int)$source['item_id'])throw new ConflictHttpException('Слот занят другим предметом.');
        $stack=($item['storage_kind']??'none')==='chest'?1:(int)$item['stack_size'];
        $available=$stack-(int)($target['item_quantity']??0);$moved=min($quantity,$available);
        if($moved<1)throw new ConflictHttpException('Стопка заполнена.');
        if(!$target&&$moved===(int)$source['item_quantity'])$this->write($sourceId,['container_id'=>$containerId?:null,'slot'=>$position]);
        else {
            $left=(int)$source['item_quantity']-$moved;
            $this->write($sourceId,['item_id'=>$left?$source['item_id']:null,'item_quantity'=>$left]);
            if($target)$this->write((int)$target['id'],['item_quantity'=>(int)$target['item_quantity']+$moved]);
            else $this->s->insert('craft_inventory',$where+['item_id'=>$source['item_id'],'item_quantity'=>$moved]);
        }
        if($container&&(int)$source['container_id']!==$containerId) {
            if(!$this->one('craft_container',['id'=>$containerId]))$this->s->insert('craft_container',$container);
            $wear=$this->settings()['chest_wear'];
            if($wear)$this->writeContainer($containerId,max(0,(int)$container['durability']-$wear));
        }
        return $moved;
    }
    private function writeContainer(int $id,int $durability): void
    {
        if($this->s->db->createCommand()->update('craft_container',['durability'=>$durability],['id'=>$id])->execute()!==1)throw new \RuntimeException('Container write failed.');
    }
}
