<?php
use common\services\game\WorldMigration;

/** No policy publication, enrollment or illness during installation. */
class m260927_130000_world_nights extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_night_policy', [
            'id' => $this->primaryKey(), 'world_id' => $this->integer()->notNull(), 'version' => $this->integer()->notNull(),
            'activated_at' => $this->integer()->notNull(), 'day_seconds' => $this->integer()->notNull(),
            'night_offset' => $this->integer()->notNull(), 'night_seconds' => $this->integer()->notNull(),
            'max_severity' => $this->integer()->notNull(), 'recovery_nights' => $this->integer()->notNull(),
            'mild_efficiency_bps' => $this->integer()->notNull(), 'severe_efficiency_bps' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(),
        ]);
        $this->index('ux_night_policy_world_version', 'world_night_policy', ['world_id', 'version'], true);
        $this->index('ux_night_policy_operation', 'world_night_policy', ['operation_id'], true);
        $this->table('world_night_period', [
            'id' => $this->primaryKey(), 'policy_id' => $this->integer()->notNull(), 'sequence' => $this->integer()->notNull(),
            'started_at' => $this->integer()->notNull(), 'ended_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_night_period_sequence', 'world_night_period', ['policy_id', 'sequence'], true);
        $this->table('actor_night_health', [
            'actor_id' => $this->integer()->notNull(), 'policy_id' => $this->integer()->notNull(), 'membership_id' => $this->integer(),
            'eligible_from' => $this->integer()->notNull(), 'grace_until' => $this->integer()->notNull(), 'next_sequence' => $this->integer()->notNull(),
            'severity' => $this->integer()->notNull()->defaultValue(0), 'recovery_progress' => $this->integer()->notNull()->defaultValue(0),
            'exposure_nights' => $this->integer()->notNull()->defaultValue(0), 'onset_at' => $this->integer(),
            'processed_until' => $this->integer(), 'revision' => $this->integer()->notNull()->defaultValue(1), 'PRIMARY KEY ([[actor_id]])',
        ]);
        $this->index('ux_night_health_membership', 'actor_night_health', ['membership_id'], true);
        $this->table('world_night_resolution', [
            'id' => $this->primaryKey(), 'actor_id' => $this->integer()->notNull(), 'period_id' => $this->integer()->notNull(),
            'outcome' => $this->string(24)->notNull(), 'severity_before' => $this->integer()->notNull(), 'severity_after' => $this->integer()->notNull(),
            'recovery_progress' => $this->integer()->notNull(), 'deployment_id' => $this->integer(), 'coverage_json' => $this->text()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'resolved_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_night_resolution_actor_period', 'world_night_resolution', ['actor_id', 'period_id'], true);
        $this->index('ux_night_resolution_place_period', 'world_night_resolution', ['deployment_id', 'period_id'], true);
        $this->index('ux_night_resolution_operation', 'world_night_resolution', ['operation_id'], true);
        $this->index('idx_night_resolution_history', 'world_night_resolution', ['actor_id', 'id']);
        $n = 0;
        foreach ([
            'world_night_policy' => ['world_id' => 'world_node', 'operation_id' => 'game_operation'],
            'world_night_period' => ['policy_id' => 'world_night_policy'],
            'actor_night_health' => ['actor_id' => 'game_actor', 'policy_id' => 'world_night_policy', 'membership_id' => 'world_membership'],
            'world_night_resolution' => ['actor_id' => 'game_actor', 'period_id' => 'world_night_period', 'deployment_id' => 'world_shelter_deployment', 'operation_id' => 'game_operation'],
        ] as $table => $columns) foreach ($columns as $column => $parent) $this->foreign('fk_night_' . ++$n, $table, $column, $parent);
    }
}
