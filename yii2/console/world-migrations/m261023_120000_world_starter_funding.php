<?php
use common\modules\world\support\WorldDomainMigration;

class m261023_120000_world_starter_funding extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_starter_funding', [
            'world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'source_node_id' => $this->integer()->notNull(), 'destination_node_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 4)->notNull(), 'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'operation_id' => $this->string(32)->notNull(), 'transfer_id' => $this->integer(),
            'created_at' => $this->integer()->notNull(), 'paid_at' => $this->integer(),
        ], ['world_id' => ['world_node'], 'user_id' => ['user'], 'source_node_id' => ['world_node'],
            'destination_node_id' => ['world_node'], 'operation_id' => ['game_operation'], 'transfer_id' => ['economy_transfer', 'id', false]],
            [['world_id', 'user_id'], ['destination_node_id']], [['source_node_id', 'status', 'id']]);
    }
}
