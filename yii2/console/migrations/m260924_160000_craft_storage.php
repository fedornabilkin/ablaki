<?php
use yii\db\Migration;
use yii\db\Query;

/** Additive and restartable on MySQL; existing items, including overflow, are retained. */
class m260924_160000_craft_storage extends Migration
{
    public function safeUp()
    {
        foreach (['slot_price'=>10,'elixir_slots'=>5,'elixir_days'=>7,'chest_slots'=>10,'chest_durability'=>100,'chest_wear'=>1] as $field=>$value) {
            if (!$this->db->schema->getTableSchema('craft_meta',true)->getColumn($field)) $this->addColumn('craft_meta',$field,$this->integer()->notNull()->defaultValue($value));
        }
        if (!$this->db->schema->getTableSchema('craft_item',true)->getColumn('storage_kind')) {
            $this->addColumn('craft_item','storage_kind',$this->string(16)->notNull()->defaultValue('none'));
        }
        if (!$this->db->schema->getTableSchema('craft_inventory',true)->getColumn('container_id')) {
            $this->addColumn('craft_inventory','container_id',$this->integer()->null());
            $this->createIndex('idx-craft-inventory-container','craft_inventory',['user_id','container_id']);
        }
        if (!$this->db->schema->getTableSchema('craft_capacity',true)) $this->createTable('craft_capacity',[
            'user_id'=>$this->integer()->notNull(),'permanent_slots'=>$this->integer()->notNull()->defaultValue(20),'PRIMARY KEY ([[user_id]])',
        ]);
        if (!$this->db->schema->getTableSchema('craft_slot_lease',true)) {
            $this->createTable('craft_slot_lease',['id'=>$this->primaryKey(),'user_id'=>$this->integer()->notNull(),'slots'=>$this->integer()->notNull(),'expires_at'=>$this->integer()->notNull()]);
            $this->createIndex('idx-craft-lease-owner','craft_slot_lease',['user_id','expires_at']);
        }
        if (!$this->db->schema->getTableSchema('craft_container',true)) $this->createTable('craft_container',[
            'id'=>$this->integer()->notNull(),'user_id'=>$this->integer()->notNull(),'capacity'=>$this->integer()->notNull(),'durability'=>$this->integer()->notNull(),'max_durability'=>$this->integer()->notNull(),'PRIMARY KEY ([[id]])',
        ]);
        // Functional chests are individual items. New installations are handled by the seed too.
        $this->db->schema->refresh();
        $store=new \common\modules\craft\service\CraftStorage($this->db);
        $inventory=new \common\modules\craft\service\CraftInventory($store);
        $this->db->transaction(function()use($store,$inventory){
            $store->lock('craft_meta',['id'=>1]);
            $this->update('craft_item',['storage_kind'=>'chest','stack_size'=>1],['code'=>'classic-chest','storage_kind'=>'none']);
            $owners=(new Query())->select('user_id')->distinct()->from('craft_inventory')->column($this->db);
            foreach ($owners as $owner) $inventory->initialize((int)$owner);
        });
    }
    public function safeDown() { return false; }
}
