<?php
use common\services\game\WorldDomainMigration;

class m260928_002600_world_economic_periods extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_economy_policy', ['version' => $this->integer()->notNull(), 'period_seconds' => $this->integer()->notNull(), 'epoch_at' => $this->integer()->notNull(), 'effective_from_sequence' => $this->bigInteger()->notNull(), 'rules_json' => $this->text()->notNull(), 'status' => $this->string(16)->notNull()], ['world_id' => ['world_node'], 'operation_id' => ['game_operation']], [['world_id', 'version'], ['operation_id']]);
        $this->domain('world_tick', ['sequence' => $this->bigInteger()->notNull(), 'status' => $this->string(16)->notNull(), 'started_at' => $this->integer(), 'closed_at' => $this->integer(), 'expected_tasks' => $this->integer()->notNull(), 'completed_tasks' => $this->integer()->notNull()->defaultValue(0)],
            ['world_id' => ['world_node'], 'policy_id' => ['world_economy_policy']], [['world_id', 'sequence']], [['world_id', 'status', 'sequence']]);
        $this->domain('world_tick_task', ['task_type' => $this->string(40)->notNull(), 'status' => $this->string(16)->notNull(), 'lease_until' => $this->bigInteger(), 'fencing_token' => $this->integer()->notNull()->defaultValue(0), 'attempts' => $this->integer()->notNull()->defaultValue(0), 'completed_at' => $this->integer()],
            ['tick_id' => ['world_tick'], 'node_id' => ['world_node'], 'job_id' => ['game_job', 'id', false]], [['tick_id', 'node_id', 'task_type']], [['status', 'lease_until', 'id']]);
        $this->domain('world_tick_snapshot', ['version' => $this->integer()->notNull(), 'state_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull()], ['tick_id' => ['world_tick'], 'node_id' => ['world_node'], 'operation_id' => ['game_operation']], [['tick_id', 'node_id', 'version']], [['operation_id']]);
        $this->domain('world_period_state', ['state_json' => $this->text()->notNull(), 'captured_at' => $this->integer()->notNull()], ['node_id' => ['world_node'], 'tick_id' => ['world_tick']], [], [], ['node_id', 'tick_id']);
        $this->domain('world_period_checkpoint', ['settled_through_sequence' => $this->bigInteger()->notNull()->defaultValue(0), 'revision' => $this->integer()->notNull()->defaultValue(1)], ['node_id' => ['world_node'], 'world_id' => ['world_node']], [], [], ['node_id']);
        $this->domain('world_period_effect', ['effective_from_sequence' => $this->bigInteger()->notNull(), 'effective_to_sequence' => $this->bigInteger(), 'effect_code' => $this->string(64)->notNull(), 'values_json' => $this->text()->notNull()], ['node_id' => ['world_node'], 'operation_id' => ['game_operation']], [['node_id', 'effect_code', 'effective_from_sequence']], [['node_id', 'effective_to_sequence']]);
    }
}
