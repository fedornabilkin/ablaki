<?php
use common\services\game\WorldMigration;

/** Paid ready premises, not the future timed construction queue. */
class m260927_110000_world_premises extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_premises_offer', [
            'id' => $this->primaryKey(), 'settlement_id' => $this->integer()->notNull(),
            'template_revision_id' => $this->integer()->notNull(), 'name' => $this->string(120)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('published'),
            'operation_id' => $this->string(32)->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_world_premises_offer_operation', 'world_premises_offer', ['operation_id'], true);
        $this->index('idx_world_premises_offers', 'world_premises_offer', ['settlement_id', 'status', 'id']);
        foreach (['settlement_id' => 'world_node', 'template_revision_id' => 'world_template_revision', 'operation_id' => 'game_operation'] as $column => $parent) $this->foreign('fk_premises_offer_' . $column, 'world_premises_offer', $column, $parent);
        $this->table('world_premises_purchase', [
            'id' => $this->primaryKey(), 'offer_id' => $this->integer()->notNull(), 'plot_id' => $this->integer()->notNull(),
            'building_id' => $this->integer()->notNull(), 'room_id' => $this->integer()->notNull(),
            'user_id' => $this->reference('user') . ' NOT NULL', 'area' => $this->integer()->notNull(),
            'transfer_id' => $this->integer()->notNull(), 'operation_id' => $this->string(32)->notNull(),
            'terms_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        foreach (['building_id', 'room_id', 'operation_id', 'transfer_id'] as $column) $this->index('ux_premises_purchase_' . $column, 'world_premises_purchase', [$column], true);
        $this->index('idx_premises_purchase_plot', 'world_premises_purchase', ['plot_id', 'id']);
        foreach (['offer_id' => 'world_premises_offer', 'plot_id' => 'world_node', 'building_id' => 'world_node', 'room_id' => 'world_node', 'user_id' => 'user', 'transfer_id' => 'economy_transfer', 'operation_id' => 'game_operation'] as $column => $parent) $this->foreign('fk_premises_purchase_' . $column, 'world_premises_purchase', $column, $parent);
    }
}
