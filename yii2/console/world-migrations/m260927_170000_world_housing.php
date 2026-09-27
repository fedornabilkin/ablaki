<?php
use common\services\game\WorldMigration;

/** New housing only; existing shelters, purchases and night results remain unchanged. */
class m260927_170000_world_housing extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_housing_place', [
            'id' => $this->primaryKey(), 'room_id' => $this->reference('world_node') . ' NOT NULL',
            'plot_id' => $this->reference('world_node') . ' NOT NULL', 'purchase_id' => $this->reference('world_premises_purchase') . ' NOT NULL',
            'operation_id' => $this->reference('game_operation') . ' NOT NULL', 'created_at' => $this->integer()->notNull(),
        ]);
        foreach (['room_id', 'purchase_id', 'operation_id'] as $column) $this->index('ux_housing_place_' . $column, 'world_housing_place', [$column], true);
        $this->table('world_housing_interval', [
            'id' => $this->primaryKey(), 'place_id' => $this->reference('world_housing_place') . ' NOT NULL',
            'actor_id' => $this->reference('game_actor') . ' NOT NULL',
            'active_place_id' => $this->reference('world_housing_place'), 'active_actor_id' => $this->reference('game_actor'),
            'started_at' => $this->integer()->notNull(), 'ended_at' => $this->integer(),
            'operation_id' => $this->reference('game_operation') . ' NOT NULL',
        ]);
        foreach (['active_place_id', 'active_actor_id', 'operation_id'] as $column) $this->index('ux_housing_interval_' . $column, 'world_housing_interval', [$column], true);
        $this->index('ix_housing_interval_history', 'world_housing_interval', ['actor_id', 'place_id', 'started_at']);
        $this->table('world_housing_night_claim', [
            'resolution_id' => $this->reference('world_night_resolution') . ' NOT NULL PRIMARY KEY',
            'place_id' => $this->reference('world_housing_place') . ' NOT NULL',
            'period_id' => $this->reference('world_night_period') . ' NOT NULL',
        ]);
        $this->index('ux_housing_night_place', 'world_housing_night_claim', ['place_id', 'period_id'], true);
        $n = 0;
        foreach ([
            'world_housing_place' => ['room_id' => 'world_node', 'plot_id' => 'world_node', 'purchase_id' => 'world_premises_purchase', 'operation_id' => 'game_operation'],
            'world_housing_interval' => ['place_id' => 'world_housing_place', 'actor_id' => 'game_actor', 'active_place_id' => 'world_housing_place', 'active_actor_id' => 'game_actor', 'operation_id' => 'game_operation'],
            'world_housing_night_claim' => ['resolution_id' => 'world_night_resolution', 'place_id' => 'world_housing_place', 'period_id' => 'world_night_period'],
        ] as $table => $columns) foreach ($columns as $column => $parent) $this->foreign('fk_housing_' . ++$n, $table, $column, $parent);
    }
}
