<?php
use common\modules\world\support\WorldMigration;

class m260927_120000_world_shelter extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_starter_grant', [
            'id' => $this->primaryKey(), 'user_id' => $this->reference('user') . ' NOT NULL',
            'grant_code' => $this->string(80)->notNull(), 'instance_id' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'claimed_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_starter_grant_user_code', 'world_starter_grant', ['user_id', 'grant_code'], true);
        $this->index('ux_starter_grant_instance', 'world_starter_grant', ['instance_id'], true);
        $this->index('ux_starter_grant_operation', 'world_starter_grant', ['operation_id'], true);
        $this->table('world_shelter_deployment', [
            'id' => $this->primaryKey(), 'instance_id' => $this->integer()->notNull(),
            'node_id' => $this->integer()->notNull(), 'plot_id' => $this->integer()->notNull(),
            // Nullable unique key: one current deployment, unlimited closed intervals.
            'active_instance_id' => $this->integer(), 'started_at' => $this->integer()->notNull(),
            'ended_at' => $this->integer(), 'protected_until' => $this->integer()->notNull(),
            'durability_at_start' => $this->integer()->notNull(), 'wear_remainder' => $this->integer()->notNull(),
            'daily_wear' => $this->integer()->notNull(), 'policy_version' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(),
        ]);
        foreach (['active_instance_id', 'node_id', 'operation_id'] as $column) $this->index('ux_shelter_deploy_' . $column, 'world_shelter_deployment', [$column], true);
        $this->index('idx_shelter_instance_history', 'world_shelter_deployment', ['instance_id', 'started_at']);
        $this->table('world_lodging_interval', [
            'id' => $this->primaryKey(), 'actor_id' => $this->integer()->notNull(), 'deployment_id' => $this->integer()->notNull(),
            'active_actor_id' => $this->integer(), 'active_deployment_id' => $this->integer(),
            'started_at' => $this->integer()->notNull(), 'ended_at' => $this->integer(),
            'operation_id' => $this->string(32)->notNull(),
        ]);
        foreach (['active_actor_id', 'active_deployment_id', 'operation_id'] as $column) $this->index('ux_lodging_' . $column, 'world_lodging_interval', [$column], true);
        $this->index('idx_lodging_actor_history', 'world_lodging_interval', ['actor_id', 'started_at']);
        $relations = [
            'world_starter_grant' => ['user_id' => 'user', 'instance_id' => 'craft_equipment_instance', 'operation_id' => 'game_operation'],
            'world_shelter_deployment' => ['instance_id' => 'craft_equipment_instance', 'active_instance_id' => 'craft_equipment_instance', 'node_id' => 'world_node', 'plot_id' => 'world_node', 'operation_id' => 'game_operation'],
            'world_lodging_interval' => ['actor_id' => 'game_actor', 'active_actor_id' => 'game_actor', 'deployment_id' => 'world_shelter_deployment', 'active_deployment_id' => 'world_shelter_deployment', 'operation_id' => 'game_operation'],
        ];
        $n = 0;
        foreach ($relations as $table => $columns) foreach ($columns as $column => $parent) $this->foreign('fk_shelter_' . ++$n, $table, $column, $parent);
    }
}
