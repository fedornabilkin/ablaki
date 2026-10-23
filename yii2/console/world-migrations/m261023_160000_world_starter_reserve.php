<?php
use common\modules\world\support\WorldDomainMigration;

class m261023_160000_world_starter_reserve extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_starter_reserve', [
            'node_id' => $this->integer()->notNull(), 'payout_cr' => $this->decimal(19, 4)->notNull(),
            'commitment_id' => $this->integer(), 'revision' => $this->integer()->notNull()->defaultValue(1),
            'operation_id' => $this->string(32)->notNull(), 'updated_at' => $this->integer()->notNull(),
        ], ['node_id' => ['world_node'], 'commitment_id' => ['economy_spending_commitment', 'id', false], 'operation_id' => ['game_operation']], [['node_id']]);
    }
}
