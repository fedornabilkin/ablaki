<?php
use common\modules\world\support\WorldDomainMigration;

class m261022_120000_world_finite_supplies extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_supply_claim', [
            'world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'item_id' => $this->integer()->notNull(), 'item_code' => $this->string(80)->notNull(), 'ordinal' => $this->integer()->notNull(),
            'quantity' => $this->integer()->notNull(), 'period_key' => $this->string(8)->notNull(),
            'grant_limit' => $this->integer()->notNull(), 'base_quantity' => $this->integer()->notNull(),
            'initial_multiplier' => $this->integer()->notNull(),
            'event_id' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull(),
        ], ['world_id' => ['world_node'], 'user_id' => ['user'], 'item_id' => ['craft_item'], 'event_id' => ['craft_event']],
            [['world_id', 'user_id', 'item_id', 'ordinal'], ['world_id', 'user_id', 'item_id', 'period_key']], [['user_id', 'created_at']]);
    }
}
