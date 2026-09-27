<?php
use common\services\game\WorldMigration;

class m260927_150000_world_garden extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_garden_offer', [
            'id' => $this->primaryKey(), 'settlement_id' => $this->reference('world_node') . ' NOT NULL',
            'active_settlement_id' => $this->reference('world_node'), 'name' => $this->string(120)->notNull(),
            'price' => $this->decimal(19, 4)->notNull(), 'base_price' => $this->decimal(19, 4)->notNull(),
            'operation_id' => $this->reference('game_operation') . ' NOT NULL', 'created_at' => $this->integer()->notNull(),
        ]);
        $this->table('world_garden_purchase', [
            'id' => $this->primaryKey(), 'node_id' => $this->reference('world_node') . ' NOT NULL',
            'membership_id' => $this->reference('world_membership') . ' NOT NULL', 'offer_id' => $this->reference('world_garden_offer') . ' NOT NULL',
            'transfer_id' => $this->reference('economy_transfer') . ' NOT NULL', 'operation_id' => $this->reference('game_operation') . ' NOT NULL',
            'terms_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->table('world_expansion_policy', [
            'id' => $this->primaryKey(), 'node_id' => $this->reference('world_node') . ' NOT NULL', 'kind' => $this->string(32)->notNull(),
            'initial_open' => $this->integer()->notNull(), 'place_limit' => $this->integer()->notNull(), 'base_price' => $this->decimal(19, 4)->notNull(),
            'curve' => $this->string(16)->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1),
            'operation_id' => $this->reference('game_operation') . ' NOT NULL',
        ]);
        $this->table('world_expansion_entitlement', [
            'id' => $this->primaryKey(), 'policy_id' => $this->reference('world_expansion_policy') . ' NOT NULL',
            'node_id' => $this->reference('world_node') . ' NOT NULL', 'ordinal' => $this->integer()->notNull(),
            'price' => $this->decimal(19, 4)->notNull(), 'policy_revision' => $this->integer()->notNull(),
            'transfer_id' => $this->reference('economy_transfer'), 'operation_id' => $this->reference('game_operation') . ' NOT NULL', 'created_at' => $this->integer()->notNull(),
        ]);
        foreach (['active_settlement_id', 'operation_id'] as $column) $this->index('ux_garden_offer_' . $column, 'world_garden_offer', [$column], true);
        foreach (['node_id', 'membership_id', 'transfer_id', 'operation_id'] as $column) $this->index('ux_garden_buy_' . $column, 'world_garden_purchase', [$column], true);
        $this->index('ux_expansion_scope', 'world_expansion_policy', ['node_id', 'kind'], true);
        $this->index('ux_expansion_ordinal', 'world_expansion_entitlement', ['policy_id', 'ordinal'], true);
        $this->index('ux_expansion_node', 'world_expansion_entitlement', ['node_id'], true);
        $this->index('ux_expansion_operation', 'world_expansion_entitlement', ['operation_id', 'ordinal'], true);
        $n = 0;
        foreach ([
            'world_garden_offer' => ['settlement_id' => 'world_node', 'active_settlement_id' => 'world_node', 'operation_id' => 'game_operation'],
            'world_garden_purchase' => ['node_id' => 'world_node', 'membership_id' => 'world_membership', 'offer_id' => 'world_garden_offer', 'transfer_id' => 'economy_transfer', 'operation_id' => 'game_operation'],
            'world_expansion_policy' => ['node_id' => 'world_node', 'operation_id' => 'game_operation'],
            'world_expansion_entitlement' => ['policy_id' => 'world_expansion_policy', 'node_id' => 'world_node', 'transfer_id' => 'economy_transfer', 'operation_id' => 'game_operation'],
        ] as $table => $columns) foreach ($columns as $column => $parent) $this->foreign('fk_garden_' . ++$n, $table, $column, $parent);
    }
}
