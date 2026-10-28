<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Connection;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** All inventory writers hold the catalog lock, then the owner lock, for the whole transaction. */
class CraftStorage
{
    public const SLOT_LIMIT=50;
    public $db;
    public $operationId;
    public $protectedInstances = [];
    public $workspaceNodeId;
    public $allowPlacedChests = false;
    public function __construct(Connection $db) { $this->db=$db; }
    /** Persisted format wins over environment flags: never reinterpret installed items as backpack rows. */
    public function isCanonical(): bool
    {
        // A reused service/worker must observe the format switch after acquiring the catalog lock.
        return $this->db->schema->getTableSchema('world_registry') && (bool)(new Query())->select('storage_v2')->from('world_registry')->where(['id' => 1])->scalar($this->db);
    }
    public function lock(string $table,array $where): array
    {
        if (!$this->db->getTransaction()) throw new \RuntimeException('Transaction required.');
        $query=(new Query())->from($table)->where($where);
        if ($this->db->driverName==='sqlite') {
            $this->db->createCommand()->update($table,['id'=>new \yii\db\Expression('[[id]]')],$where)->execute();
            $row=$query->one($this->db);
        } else {
            if (!in_array($this->db->driverName,['mysql','pgsql'],true)) throw new \RuntimeException('Unsupported database.');
            $command=$query->createCommand($this->db);
            $row=$this->db->createCommand($command->sql.' FOR UPDATE',$command->params)->queryOne();
        }
        if (!$row) throw new ConflictHttpException('Данные крафта недоступны.');
        return $row;
    }
    public function rows(string $table,array $where=[]): array
    {
        $keys=$this->db->schema->getTableSchema($table)->primaryKey;
        return (new Query())->from($table)->where($where)->orderBy(array_fill_keys($keys,SORT_ASC))->all($this->db);
    }
    public function insert(string $table,array $values): int
    {
        if ($table === 'craft_inventory' && $this->isCanonical()) throw new \LogicException('Use CanonicalInventory for storage_v2 writes.');
        if ($this->db->createCommand()->insert($table,$values)->execute()!==1) throw new \RuntimeException('Craft write failed.');
        return isset($values['id'])?(int)$values['id']:(int)$this->db->getLastInsertID();
    }
    public function quantities(int $userId): array
    {
        $inventory=new CraftInventory($this);$active=$inventory->capacity($userId)['active_slots'];$result=[];
        $filled=array_flip((new Query())->select('container_id')->distinct()->from('craft_inventory')->where(['user_id'=>$userId])->andWhere(['not',['container_id'=>null]])->andWhere(['>','item_quantity',0])->column($this->db));
        if ($this->isCanonical()) $filled = array_flip((new Query())->select('s.container_inventory_id')->distinct()->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')->where(['s.kind' => 'chest', 's.owner_user_id' => $userId])->andWhere(['>', 'i.item_quantity', 0])->column($this->db));
        foreach($inventory->layout($userId) as $slot)if((int)$slot['slot']<=$active&&!isset($filled[$slot['id']]))$result[(int)$slot['item_id']]=($result[(int)$slot['item_id']]??0)+(int)$slot['item_quantity'];
        return $result;
    }
    public function move(int $userId,array $item,int $delta,?int $slotId=null): void
    {
        if ($this->isCanonical()) { (new CanonicalInventory($this))->change($userId, $item, $delta, $slotId); return; }
        if(!$this->db->getTransaction())throw new \RuntimeException('Inventory transaction required.');
        if(!$delta)return;
        $inventory=new CraftInventory($this);$inventory->synchronize($userId);
        $active=$inventory->capacity($userId)['active_slots'];$slots=$inventory->layout($userId);
        if($delta<0) {
            $sources=array_filter($slots,static function($slot)use($item,$slotId,$active,$inventory){
                return (int)$slot['item_id']===(int)$item['id']&&($slotId!==null?(int)$slot['id']===$slotId:(int)$slot['slot']<=$active)&&!$inventory->nonempty((int)$slot['id']);
            });
            if(array_sum(array_column($sources,'item_quantity'))<-$delta)throw new ConflictHttpException('Не хватает доступных предметов. Сначала освободите содержимое сундука.');
            foreach($sources as $slot) {
                $take=min(-$delta,(int)$slot['item_quantity']);$left=(int)$slot['item_quantity']-$take;
                $inventory->write((int)$slot['id'],['item_quantity'=>$left,'item_id'=>$left?$item['id']:null]);
                if(!$left)$this->db->createCommand()->delete('craft_container',['id'=>$slot['id'],'user_id'=>$userId])->execute();
                $delta+=$take;if(!$delta)return;
            }
        } else {
            if($slotId!==null)throw new \InvalidArgumentException('Positive slot targeting is unsupported.');
            $limit=($item['storage_kind']??'none')==='chest'?1:(int)$item['stack_size'];
            if($limit<1||$limit>10000)throw new ConflictHttpException('Неверный размер стопки.');
            $occupied=[];
            foreach($slots as $slot) {
                $position=(int)$slot['slot'];$occupied[$position]=true;
                if($position>$active||(int)$slot['item_id']!==(int)$item['id'])continue;
                $add=min($delta,max(0,$limit-(int)$slot['item_quantity']));if(!$add)continue;
                $inventory->write((int)$slot['id'],['item_quantity'=>(int)$slot['item_quantity']+$add]);
                $delta-=$add;if(!$delta)return;
            }
            for($position=1;$position<=$active&&$delta>0;$position++) {
                if(isset($occupied[$position]))continue;$add=min($delta,$limit);
                $empty=(new Query())->from('craft_inventory')->where(['user_id'=>$userId,'container_id'=>null,'item_id'=>null])->orderBy('id')->one($this->db);
                $values=['item_id'=>$item['id'],'item_quantity'=>$add,'slot'=>$position];
                if($empty)$inventory->write((int)$empty['id'],$values);else $this->insert('craft_inventory',$values+['user_id'=>$userId]);
                $delta-=$add;
            }
            if($delta>0)throw new ConflictHttpException('Активные слоты заполнены. Объедините предметы, используйте сундук или откройте дополнительные слоты.');
        }
    }
    public function merge(int $userId,array $item,int $sourceId,int $targetId): int
    {
        if(!$this->db->getTransaction())throw new \RuntimeException('Inventory transaction required.');
        $target=(new Query())->from('craft_inventory')->where(['id'=>$targetId,'user_id'=>$userId,'item_id'=>$item['id']])->one($this->db);
        $source=(new Query())->from('craft_inventory')->where(['id'=>$sourceId,'user_id'=>$userId,'item_id'=>$item['id']])->one($this->db);
        if(!$source||!$target||(int)$source['item_quantity']<1||(int)$target['item_quantity']<1)throw new ConflictHttpException('Выберите свои стопки одинаковых предметов.');
        if ($this->isCanonical()) {
            $place = (new StorageAccessPolicy($this->db))->storage($userId, (int)$target['storage_id']);
            if (!in_array($place['kind'], ['backpack', 'chest'], true)) throw new ConflictHttpException('Для установленной вещи используйте страницу размещения.');
        }
        return (new CraftInventory($this))->transfer($userId,$sourceId,(int)$target['container_id'],(int)$target['slot'],(int)$source['item_quantity']);
    }
}
