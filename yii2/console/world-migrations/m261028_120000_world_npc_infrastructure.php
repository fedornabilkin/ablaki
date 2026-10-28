<?php
use common\modules\world\support\WorldDomainMigration;

class m261028_120000_world_npc_infrastructure extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_npc_policy', [
            'world_id' => $this->integer()->notNull(), 'bot_user_id' => $this->integer()->notNull(),
            'city_node_id' => $this->integer()->notNull(), 'site_node_id' => $this->integer(),
            'enabled' => $this->smallInteger()->notNull()->defaultValue(1),
            'target_budget_cr' => $this->decimal(19, 4)->notNull()->defaultValue('20.0000'),
            'reward_cr' => $this->decimal(19, 4)->notNull()->defaultValue('1.0000'),
            'refill_interval_seconds' => $this->integer()->notNull()->defaultValue(60),
            'last_refill_at' => $this->integer()->notNull()->defaultValue(0),
            'last_shortfall_cr' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'),
            'created_by' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull(),
        ], ['world_id' => ['world_node'], 'bot_user_id' => ['user'], 'city_node_id' => ['world_node'],
            'site_node_id' => ['world_node', 'id', false], 'created_by' => ['user']], [['world_id']]);
        $this->db->createCommand()->update('world_registry', ['schema_version' => 44], ['id' => 1])->execute();
    }
}
