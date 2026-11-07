<?php
use common\modules\world\support\WorldDomainMigration;

class m261106_210000_world_npc_funding extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_npc_funding', [
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'share_bps' => $this->integer()->notNull()->defaultValue(8000),
            'interval_seconds' => $this->integer()->notNull()->defaultValue(3600),
            'daily_limit_cr' => $this->decimal(20,4)->notNull()->defaultValue('500.0000'),
            'target_cr' => $this->decimal(20,4)->notNull()->defaultValue('90.0000'),
            'sales_cr' => $this->decimal(20,4)->notNull()->defaultValue('0.0000'),
            'eligible_cr' => $this->decimal(20,4)->notNull()->defaultValue('0.0000'),
            'funded_cr' => $this->decimal(20,4)->notNull()->defaultValue('0.0000'),
            'day_spent_cr' => $this->decimal(20,4)->notNull()->defaultValue('0.0000'),
            'day_key' => $this->string(10)->notNull()->defaultValue(''),
            'carry' => $this->integer()->notNull()->defaultValue(0),
            'last_refill_at' => $this->integer()->notNull()->defaultValue(0),
            'revision' => $this->integer()->notNull()->defaultValue(1),
        ], ['site_id' => ['world_node']], [['site_id']]);
        $this->domain('world_npc_revenue', [
            'channel' => $this->string(20)->notNull(),
            'amount_cr' => $this->decimal(20,4)->notNull(),
            'eligible_cr' => $this->decimal(20,4)->notNull(),
            'share_bps' => $this->integer()->notNull(),
            'created_at' => $this->integer()->notNull(),
        ], ['site_id' => ['world_node'], 'buyer_id' => ['user'], 'node_id' => ['world_node'], 'operation_id' => ['game_operation']], [['operation_id', 'channel']], [['site_id','created_at']]);
        $this->domain('world_npc_funding_run', [
            'amount_cr' => $this->decimal(20,4)->notNull(),
            'remaining_cr' => $this->decimal(20,4)->notNull(),
            'reason' => $this->string(40)->notNull(), 'allocations_json' => $this->text()->notNull(),
            'created_at' => $this->integer()->notNull(),
        ], ['site_id' => ['world_node'], 'operation_id' => ['game_operation']], [['operation_id']], [['site_id','created_at']]);
        $this->domain('world_admin_correction', [
            'request_hash' => $this->string(64)->notNull(),
            'entity' => $this->string(100)->notNull(), 'key_json' => $this->text()->notNull(),
            'before_json' => $this->text()->notNull(), 'after_json' => $this->text()->notNull(),
            'comment' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ], ['author_id' => ['user'], 'operation_id' => ['game_operation']], [['operation_id']], [['entity','created_at']]);
        if($this->db->schema->getTableSchema('auth_item') && (new \yii\db\Query())->from('auth_item')->where(['name'=>'p-admin'])->exists($this->db)) {
            foreach(['index','view','edit','apply'] as $action) {
                $route='/world/control/'.$action;
                if(!(new \yii\db\Query())->from('auth_item')->where(['name'=>$route])->exists($this->db)) $this->db->createCommand()->insert('auth_item',['name'=>$route,'type'=>2,'description'=>'Управление миром','created_at'=>time(),'updated_at'=>time()])->execute();
                if(!(new \yii\db\Query())->from('auth_item_child')->where(['parent'=>'p-admin','child'=>$route])->exists($this->db)) $this->db->createCommand()->insert('auth_item_child',['parent'=>'p-admin','child'=>$route])->execute();
            }
            if(\Yii::$app->has('authManager') && method_exists(\Yii::$app->authManager,'invalidateCache')) \Yii::$app->authManager->invalidateCache();
        }
    }
}
