<?php
namespace common\modules\craft\service;

use yii\db\Connection;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** All inventory writers hold the catalog lock, then the owner lock, for the whole transaction. */
class CraftStorage
{
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
        return (int)$this->db->getLastInsertID();
    }
    public function quantities(int $userId): array
    {
        $result=[];
        foreach ($this->rows('craft_inventory',['user_id'=>$userId]) as $slot) if ($slot['item_id']!==null) $result[(int)$slot['item_id']]=($result[(int)$slot['item_id']]??0)+(int)$slot['item_quantity'];
        return $result;
    }
    public function move(int $userId,array $item,int $delta): void
    {
        if (!$this->db->getTransaction()) throw new \RuntimeException('Inventory transaction required.');
        if (!$delta) return;
        $slots=(new Query())->from('craft_inventory')->where(['user_id'=>$userId])->orderBy(['id'=>SORT_ASC])->all($this->db);
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
            foreach ($slots as $slot) {
                if ($slot['item_id']!==null&&(int)$slot['item_id']!==(int)$item['id']&&(int)$slot['item_quantity']>0) continue;
                $add=min($delta,max(0,$limit-(int)$slot['item_quantity']));
                if (!$add) continue;
                if ($this->db->createCommand()->update('craft_inventory',['item_id'=>$item['id'],'item_quantity'=>(int)$slot['item_quantity']+$add],['id'=>$slot['id']])->execute()!==1) throw new \RuntimeException('Inventory write failed.');
                $delta-=$add; if (!$delta) return;
            }
            $count=count($slots); $label=0;
            foreach ($slots as $slot) $label=max($label,(int)$slot['slot']);
            while ($delta>0) {
                if (++$count>200) throw new ConflictHttpException('Инвентарь заполнен (200 слотов).');
                $add=min($delta,$limit);
                $this->insert('craft_inventory',['user_id'=>$userId,'item_id'=>$item['id'],'item_quantity'=>$add,'slot'=>++$label]); $delta-=$add;
            }
        }
    }
}
