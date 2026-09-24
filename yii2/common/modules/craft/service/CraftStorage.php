<?php
namespace common\modules\craft\service;

use yii\db\Connection;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** All inventory writers hold the catalog lock, then the owner lock, for the whole transaction. */
class CraftStorage
{
    public const SLOT_LIMIT=100;
    public $db;
    public function __construct(Connection $db) { $this->db=$db; }
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
        if ($this->db->createCommand()->insert($table,$values)->execute()!==1) throw new \RuntimeException('Craft write failed.');
        return isset($values['id'])?(int)$values['id']:(int)$this->db->getLastInsertID();
    }
    public function quantities(int $userId): array
    {
        $result=[];
        foreach ($this->rows('craft_inventory',['user_id'=>$userId]) as $slot) if ($slot['item_id']!==null) $result[(int)$slot['item_id']]=($result[(int)$slot['item_id']]??0)+(int)$slot['item_quantity'];
        return $result;
    }
    public function move(int $userId,array $item,int $delta,?int $slotId=null): void
    {
        if (!$this->db->getTransaction()) throw new \RuntimeException('Inventory transaction required.');
        if (!$delta) return;
        $slots=(new Query())->from('craft_inventory')->where(['user_id'=>$userId])->orderBy(['id'=>SORT_ASC])->all($this->db);
        if($slotId!==null) {
            if($delta>0)throw new \InvalidArgumentException('Slot targeting is only available for consumption.');
            $slots=array_values(array_filter($slots,static function($slot)use($slotId,$item){return (int)$slot['id']===$slotId&&(int)$slot['item_id']===(int)$item['id'];}));
            if(!$slots)throw new ConflictHttpException('Предмет в этом слоте уже изменился.');
        }
        if ($delta<0) {
            $available=array_sum(array_map(static function($s) use($item) { return (int)$s['item_id']===(int)$item['id']?(int)$s['item_quantity']:0; },$slots));
            if ($available<-$delta) throw new ConflictHttpException('Не хватает предмета: '.trim($item['name']));
            foreach ($slots as $slot) {
                if ((int)$slot['item_id']!==(int)$item['id']) continue;
                $take=min(-$delta,(int)$slot['item_quantity']);
                if (!$take) continue;
                $left=(int)$slot['item_quantity']-$take;
                if ($this->db->createCommand()->update('craft_inventory',['item_quantity'=>$left,'item_id'=>$left?$item['id']:null],['id'=>$slot['id']])->execute()!==1) throw new \RuntimeException('Inventory write failed.');
                $delta+=$take; if (!$delta) return;
            }
        } else {
            $limit=(int)$item['stack_size'];
            if ($limit<1||$limit>10000) throw new ConflictHttpException('Неверный размер стака.');
            // Fill existing stacks before allocating cells. Empty legacy rows do not consume capacity.
            $occupied=count(array_filter($slots,static function($slot){return $slot['item_id']!==null&&(int)$slot['item_quantity']>0;}));
            foreach ($slots as $slot) {
                if ((int)$slot['item_id']!==(int)$item['id']||(int)$slot['item_quantity']<1) continue;
                $add=min($delta,max(0,$limit-(int)$slot['item_quantity']));
                if (!$add) continue;
                if ($this->db->createCommand()->update('craft_inventory',['item_id'=>$item['id'],'item_quantity'=>(int)$slot['item_quantity']+$add],['id'=>$slot['id']])->execute()!==1) throw new \RuntimeException('Inventory write failed.');
                $delta-=$add; if (!$delta) return;
            }
            foreach($slots as $slot) {
                if($slot['item_id']!==null&&(int)$slot['item_quantity']>0)continue;
                if($occupied>=self::SLOT_LIMIT)throw new ConflictHttpException('Инвентарь заполнен (100 слотов).');
                $add=min($delta,$limit);
                if($this->db->createCommand()->update('craft_inventory',['item_id'=>$item['id'],'item_quantity'=>$add],['id'=>$slot['id']])->execute()!==1)throw new \RuntimeException('Inventory write failed.');
                $occupied++;$delta-=$add;if(!$delta)return;
            }
            $label=0;
            foreach ($slots as $slot) $label=max($label,(int)$slot['slot']);
            while ($delta>0) {
                if (++$occupied>self::SLOT_LIMIT) throw new ConflictHttpException('Инвентарь заполнен (100 слотов).');
                $add=min($delta,$limit);
                $this->insert('craft_inventory',['user_id'=>$userId,'item_id'=>$item['id'],'item_quantity'=>$add,'slot'=>++$label]); $delta-=$add;
            }
        }
    }

    /** Called under the same catalog and owner locks as every inventory writer. */
    public function merge(int $userId,array $item,int $sourceId,int $targetId): int
    {
        if (!$this->db->getTransaction()) throw new \RuntimeException('Inventory transaction required.');
        $slots=(new Query())->from('craft_inventory')->where(['user_id'=>$userId,'item_id'=>$item['id'],'id'=>[$sourceId,$targetId]])->indexBy('id')->all($this->db);
        if($sourceId===$targetId||count($slots)!==2||(int)$slots[$sourceId]['item_quantity']<1||(int)$slots[$targetId]['item_quantity']<1)throw new ConflictHttpException('Объединять можно только свои стопки одинаковых предметов.');
        $limit=(int)$item['stack_size'];
        if($limit<1||$limit>10000)throw new ConflictHttpException('Неверный размер стопки.');
        $moved=min((int)$slots[$sourceId]['item_quantity'],max(0,$limit-(int)$slots[$targetId]['item_quantity']));
        if(!$moved)throw new ConflictHttpException('Стопка уже заполнена.');
        $left=(int)$slots[$sourceId]['item_quantity']-$moved;
        if($this->db->createCommand()->update('craft_inventory',['item_quantity'=>$left,'item_id'=>$left?$item['id']:null],['id'=>$sourceId,'user_id'=>$userId])->execute()!==1
            ||$this->db->createCommand()->update('craft_inventory',['item_quantity'=>(int)$slots[$targetId]['item_quantity']+$moved],['id'=>$targetId,'user_id'=>$userId])->execute()!==1)throw new \RuntimeException('Inventory write failed.');
        return $moved;
    }
}
