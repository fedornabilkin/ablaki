<?php
use common\modules\world\support\WorldMigration;

class m260927_109000_starter_orders extends WorldMigration
{
    public function safeUp()
    {
        $this->table('economy_spending_commitment', [
            'id' => $this->primaryKey(), 'account_id' => $this->integer()->notNull(), 'operation_id' => $this->string(32)->notNull(),
            'purpose' => $this->string(255)->notNull(), 'original_amount' => $this->decimal(19, 4)->notNull(),
            'remaining_amount' => $this->decimal(19, 4)->notNull(), 'created_at' => $this->integer()->notNull(), 'closed_at' => $this->integer(),
        ]);
        $this->index('ux_economy_commitment_operation', 'economy_spending_commitment', ['operation_id'], true);
        $this->foreign('fk_economy_commitment_account', 'economy_spending_commitment', 'account_id', 'economy_account');
        $this->foreign('fk_economy_commitment_operation', 'economy_spending_commitment', 'operation_id', 'game_operation');
        $this->table('economy_spending_allocation', [
            'id' => $this->primaryKey(), 'transfer_id' => $this->integer()->notNull(), 'funding_lot_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 4)->notNull(),
        ]);
        $this->index('ux_economy_spending_allocation', 'economy_spending_allocation', ['transfer_id', 'funding_lot_id'], true);
        $this->foreign('fk_economy_spend_transfer', 'economy_spending_allocation', 'transfer_id', 'economy_transfer');
        $this->foreign('fk_economy_spend_funding', 'economy_spending_allocation', 'funding_lot_id', 'economy_funding_lot');
        $this->table('economy_purchase_order', [
            'id' => $this->primaryKey(), 'node_id' => $this->integer()->notNull(), 'item_id' => $this->reference('craft_item') . ' NOT NULL',
            'commitment_id' => $this->integer()->notNull(), 'starter_history_id' => $this->integer()->notNull(),
            'quantity' => $this->integer()->notNull(), 'remaining_quantity' => $this->integer()->notNull(), 'per_user_limit' => $this->integer()->notNull(),
            'unit_price' => $this->decimal(19, 4)->notNull(), 'purpose' => $this->string(255)->notNull(), 'status' => $this->string(16)->notNull(),
            'created_by' => $this->reference('user') . ' NOT NULL', 'created_at' => $this->integer()->notNull(), 'expires_at' => $this->integer()->notNull(),
            'revision' => $this->integer()->notNull()->defaultValue(1), 'closed_at' => $this->integer(), 'close_operation_id' => $this->string(32),
        ]);
        $this->index('ux_economy_order_commitment', 'economy_purchase_order', ['commitment_id'], true);
        $this->index('idx_economy_order_node', 'economy_purchase_order', ['node_id', 'status', 'id']);
        foreach (['node_id' => 'world_node', 'item_id' => 'craft_item', 'commitment_id' => 'economy_spending_commitment', 'starter_history_id' => 'economy_parent_history', 'created_by' => 'user', 'close_operation_id' => 'game_operation'] as $column => $parent) $this->foreign('fk_economy_order_' . $column, 'economy_purchase_order', $column, $parent);
        $this->table('economy_order_fulfillment', [
            'id' => $this->primaryKey(), 'order_id' => $this->integer()->notNull(), 'user_id' => $this->reference('user') . ' NOT NULL',
            'site_node_id' => $this->integer()->notNull(), 'inventory_id' => $this->reference('craft_inventory') . ' NOT NULL',
            'quantity' => $this->integer()->notNull(), 'transfer_id' => $this->integer()->notNull(), 'operation_id' => $this->string(32)->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_economy_fulfillment_operation', 'economy_order_fulfillment', ['operation_id'], true);
        $this->index('ux_economy_fulfillment_transfer', 'economy_order_fulfillment', ['transfer_id'], true);
        $this->index('idx_economy_fulfillment_user', 'economy_order_fulfillment', ['order_id', 'user_id']);
        foreach (['order_id' => 'economy_purchase_order', 'user_id' => 'user', 'site_node_id' => 'world_node', 'inventory_id' => 'craft_inventory', 'transfer_id' => 'economy_transfer', 'operation_id' => 'game_operation'] as $column => $parent) $this->foreign('fk_economy_fulfillment_' . $column, 'economy_order_fulfillment', $column, $parent);
    }
}
