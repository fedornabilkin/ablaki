<?php
use common\modules\world\support\WorldDomainMigration;

class m261106_190000_world_npc_market extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_npc_market', [
            'last_restock_at' => $this->integer()->notNull()->defaultValue(0),
        ], ['city_id' => ['world_node'], 'bot_user_id' => ['user'], 'owner_user_id' => ['user'], 'storage_id' => ['craft_storage']], [['city_id'], ['storage_id']]);
        $this->domain('world_market_trade', [
            'direction' => $this->string(4)->notNull(), 'quantity' => $this->integer()->notNull(),
            'unit_price_cr' => $this->decimal(20, 4)->notNull(), 'total_cr' => $this->decimal(20, 4)->notNull(),
            'burned_cr' => $this->decimal(20, 4)->notNull(), 'created_at' => $this->integer()->notNull(),
        ], ['market_id' => ['world_npc_market'], 'user_id' => ['user'], 'item_id' => ['craft_item'],
            'operation_id' => ['game_operation'], 'profile_id' => ['world_economy_profile']], [['operation_id']], [['user_id', 'direction', 'created_at']]);
    }
}
