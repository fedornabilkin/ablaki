<?php
use common\services\game\WorldMigration;

class m260927_210000_world_demolition extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_building_demolition', [
            'id' => $this->primaryKey(), 'purchase_id' => $this->reference('world_premises_purchase') . ' NOT NULL',
            'building_id' => $this->reference('world_node') . ' NOT NULL', 'plot_id' => $this->reference('world_node') . ' NOT NULL',
            'user_id' => $this->reference('user') . ' NOT NULL', 'area' => $this->integer()->notNull(), 'name' => $this->string(120)->notNull(),
            'operation_id' => $this->reference('game_operation') . ' NOT NULL', 'terms_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        foreach (['purchase_id', 'building_id', 'operation_id'] as $column) $this->index('ux_demolition_' . $column, 'world_building_demolition', [$column], true);
        $this->index('ix_demolition_plot', 'world_building_demolition', ['plot_id', 'id']);
        foreach (['purchase_id' => 'world_premises_purchase', 'building_id' => 'world_node', 'plot_id' => 'world_node', 'user_id' => 'user', 'operation_id' => 'game_operation'] as $column => $parent) $this->foreign('fk_demolition_' . $column, 'world_building_demolition', $column, $parent);
    }
}
