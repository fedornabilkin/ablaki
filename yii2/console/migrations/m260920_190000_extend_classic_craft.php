<?php
use yii\db\Migration;

class m260920_190000_extend_classic_craft extends Migration
{
    private function reference($table)
    {
        // Imported MySQL installations may use unsigned bigint IDs; match the actual parent.
        return $this->db->schema->getTableSchema($table, true)->columns['id']->dbType;
    }
    public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
    {
        // SQLite is only used by disposable tests; target DB suites check the foreign keys.
        if ($this->db->driverName !== 'sqlite') parent::addForeignKey($name,$table,$columns,$refTable,$refColumns,$delete,$update);
    }
    public function safeUp()
    {
        $columns = [
            'craft_category' => ['code' => $this->string(64)],
            'craft_item' => ['code'=>$this->string(64), 'kind'=>$this->string(20)->notNull()->defaultValue('material'), 'rarity'=>$this->string(20)->notNull()->defaultValue('common'), 'icon'=>$this->string(64)->notNull()->defaultValue('cube'), 'stack_size'=>$this->integer()->notNull()->defaultValue(100), 'destroyable'=>$this->smallInteger()->notNull()->defaultValue(1), 'use_xp'=>$this->integer()->notNull()->defaultValue(0), 'gather_quantity'=>$this->integer()->notNull()->defaultValue(0)],
            'craft_recipe' => ['code'=>$this->string(64), 'output_quantity'=>$this->integer()->notNull()->defaultValue(1), 'cost_credits'=>$this->integer()->notNull()->defaultValue(0), 'experience'=>$this->integer()->notNull()->defaultValue(10), 'min_level'=>$this->integer()->notNull()->defaultValue(1), 'station_id'=>$this->integer()],
        ];
        foreach ($columns as $table=>$fields) {
            foreach ($fields as $name=>$type) $this->addColumn($table,$name,$type);
            foreach ((new \yii\db\Query())->from($table)->all($this->db) as $row) $this->update($table,['code'=>'legacy-'.str_replace('craft_','',$table).'-'.$row['id']],['id'=>$row['id']]);
            $this->createIndex('ux_'.$table.'_code',$table,'code',true);
        }
        $this->createIndex('idx_craft_recipe_result','craft_recipe','item_id');
        $this->dropIndex('idx-craft_recipe-item_id','craft_recipe');
        $this->createTable('craft_station',['id'=>$this->primaryKey(),'code'=>$this->string(64)->notNull()->unique(),'name'=>$this->string(100)->notNull(),'item_id'=>$this->reference('craft_item'),'active'=>$this->smallInteger()->notNull()->defaultValue(1)]);
        $this->addForeignKey('fk_craft_station_item','craft_station','item_id','craft_item','id','RESTRICT');
        $this->addForeignKey('fk_craft_recipe_station','craft_recipe','station_id','craft_station','id','RESTRICT');
        $this->createTable('craft_dependency',['recipe_id'=>$this->reference('craft_recipe').' NOT NULL','requires_id'=>$this->reference('craft_recipe').' NOT NULL','PRIMARY KEY ([[recipe_id]], [[requires_id]])']);
        foreach (['recipe_id','requires_id'] as $field) $this->addForeignKey('fk_craft_dependency_'.$field,'craft_dependency',$field,'craft_recipe','id','RESTRICT');
        $userType=$this->reference('user').' NOT NULL';
        $this->createTable('craft_skill',['user_id'=>$userType,'category_id'=>$this->reference('craft_category').' NOT NULL','experience'=>$this->integer()->notNull()->defaultValue(0),'PRIMARY KEY ([[user_id]], [[category_id]])']);
        $this->createTable('craft_known',['user_id'=>$userType,'recipe_id'=>$this->reference('craft_recipe').' NOT NULL','quantity'=>$this->integer()->notNull()->defaultValue(0),'PRIMARY KEY ([[user_id]], [[recipe_id]])']);
        $this->createTable('craft_command',['id'=>$this->primaryKey(),'user_id'=>$userType,'request_key'=>$this->string(80)->notNull(),'fingerprint'=>$this->string(64)->notNull(),'result'=>$this->text()->notNull(),'created_at'=>$this->integer()->notNull()]);
        $this->createIndex('ux_craft_command_user_key','craft_command',['user_id','request_key'],true);
        $this->createTable('craft_event',['id'=>$this->primaryKey(),'user_id'=>$userType,'action'=>$this->string(30)->notNull(),'recipe_id'=>$this->reference('craft_recipe'),'item_id'=>$this->reference('craft_item'),'quantity'=>$this->integer()->notNull(),'credit_change'=>$this->integer()->notNull()->defaultValue(0),'details'=>$this->text(),'created_at'=>$this->integer()->notNull()]);
        $this->createIndex('idx_craft_event_user','craft_event',['user_id','id']);
        foreach (['craft_skill','craft_known','craft_command','craft_event'] as $table) $this->addForeignKey('fk_'.$table.'_user',$table,'user_id','user','id','CASCADE');
        $this->addForeignKey('fk_craft_skill_category','craft_skill','category_id','craft_category','id','RESTRICT');
        $this->addForeignKey('fk_craft_known_recipe','craft_known','recipe_id','craft_recipe','id','RESTRICT');
        $this->createTable('craft_meta',['id'=>$this->primaryKey(),'revision'=>$this->integer()->notNull()->defaultValue(1)]);
        $this->insert('craft_meta',['id'=>1,'revision'=>1]);
        // Existing slots and their contents are preserved. Duplicate legacy slot labels are not used as identity.
        $this->createIndex('idx_craft_inventory_owner_item','craft_inventory',['user_id','item_id']);
    }
    public function safeDown() { echo "Craft data must be preserved; restore a verified backup for schema rollback.\n"; return false; }
}
