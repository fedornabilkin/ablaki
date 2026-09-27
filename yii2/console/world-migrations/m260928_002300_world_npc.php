<?php
use common\services\game\WorldDomainMigration;

class m260928_002300_world_npc extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('npc', ['name' => $this->string(120)->notNull(), 'status' => $this->string(20)->notNull(), 'hired_at' => $this->integer()->notNull(), 'released_at' => $this->integer(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['actor_id' => ['game_actor'], 'owner_user_id' => ['user'], 'world_id' => ['world_node'], 'home_node_id' => ['world_node', 'id', false], 'template_revision_id' => ['world_template_revision'], 'hire_operation_id' => ['game_operation']], [['hire_operation_id']], [['owner_user_id', 'status', 'actor_id']], ['actor_id']);
        $this->domain('npc_hire_offer', ['name' => $this->string(120)->notNull(), 'price' => $this->decimal(19, 4)->notNull(), 'available_quantity' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'created_at' => $this->integer()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['settlement_id' => ['world_node'], 'template_revision_id' => ['world_template_revision'], 'operation_id' => ['game_operation']], [['operation_id']], [['settlement_id', 'status', 'id']]);
        $this->domain('npc_hire_receipt', ['terms_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull()], ['offer_id' => ['npc_hire_offer'], 'actor_id' => ['npc', 'actor_id'], 'operation_id' => ['game_operation'], 'transfer_id' => ['economy_transfer']], [['actor_id'], ['operation_id'], ['transfer_id']]);
        $this->domain('world_job_position', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull(), 'min_level' => $this->integer()->notNull(), 'wage' => $this->decimal(19, 4)->notNull(), 'wage_period_seconds' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['building_id' => ['world_node'], 'profession_id' => ['profession'], 'template_revision_id' => ['world_template_revision']], [['building_id', 'code']], [['building_id', 'status', 'id']]);
        $this->domain('npc_assignment', ['started_at' => $this->integer()->notNull(), 'ended_at' => $this->integer(), 'effective_from_sequence' => $this->bigInteger()->notNull(), 'terms_json' => $this->text()->notNull()],
            ['actor_id' => ['npc', 'actor_id'], 'position_id' => ['world_job_position'], 'operation_id' => ['game_operation']], [['operation_id']], [['actor_id', 'ended_at', 'id']]);
        $this->domain('npc_active_assignment', [], ['actor_id' => ['npc', 'actor_id'], 'assignment_id' => ['npc_assignment'], 'position_id' => ['world_job_position']], [['assignment_id'], ['position_id']], [], ['actor_id']);
        $this->domain('npc_training_program', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull(), 'version' => $this->integer()->notNull(), 'price' => $this->decimal(19, 4)->notNull(), 'duration_seconds' => $this->integer()->notNull(), 'xp' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'requirements_json' => $this->text()->notNull()],
            ['profession_revision_id' => ['profession_revision'], 'settlement_id' => ['world_node']], [['code', 'version']], [['settlement_id', 'status', 'id']]);
        $this->domain('npc_training', ['status' => $this->string(16)->notNull(), 'started_at' => $this->integer()->notNull(), 'finish_at' => $this->bigInteger()->notNull(), 'closed_at' => $this->integer(), 'terms_json' => $this->text()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['actor_id' => ['npc', 'actor_id'], 'program_id' => ['npc_training_program'], 'budget_account_id' => ['economy_account'], 'operation_id' => ['game_operation'], 'transfer_id' => ['economy_transfer']], [['operation_id']], [['status', 'finish_at', 'id']]);
        $this->domain('npc_active_training', [], ['actor_id' => ['npc', 'actor_id'], 'training_id' => ['npc_training']], [['training_id']], [], ['actor_id']);
        $this->domain('npc_training_result', ['xp' => $this->integer()->notNull(), 'completed_at' => $this->integer()->notNull()], ['training_id' => ['npc_training'], 'award_id' => ['progression_award'], 'operation_id' => ['game_operation']], [['award_id'], ['operation_id']], [], ['training_id']);
        $this->domain('world_population_snapshot', ['period_sequence' => $this->bigInteger()->notNull(), 'residents' => $this->integer()->notNull(), 'materialized_npc' => $this->integer()->notNull(), 'unemployed' => $this->integer()->notNull(), 'housing_capacity' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()], ['node_id' => ['world_node']], [['node_id', 'period_sequence']]);
    }
}
