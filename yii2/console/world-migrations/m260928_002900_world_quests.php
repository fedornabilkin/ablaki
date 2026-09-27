<?php
use common\services\game\WorldDomainMigration;

class m260928_002900_world_quests extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('quest_template', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull()], [], [['code']]);
        $this->domain('quest_revision', ['version' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'repeat_policy' => $this->string(24)->notNull(), 'duration_seconds' => $this->integer(), 'rewards_json' => $this->text()->notNull(), 'published_at' => $this->integer()],
            ['template_id' => ['quest_template'], 'requirement_revision_id' => ['requirement_revision', 'id', false], 'author_user_id' => ['user'], 'reward_budget_account_id' => ['economy_account', 'id', false]], [['template_id', 'version']], [['status', 'id']]);
        $this->domain('quest_objective', ['code' => $this->string(64)->notNull(), 'kind' => $this->string(32)->notNull(), 'event_type' => $this->string(80), 'mode' => $this->string(24)->notNull(), 'required_quantity' => $this->integer()->notNull(), 'rules_json' => $this->text()->notNull()], ['revision_id' => ['quest_revision']], [['revision_id', 'code']]);
        $this->domain('quest_prerequisite', [], ['revision_id' => ['quest_revision'], 'required_template_id' => ['quest_template']], [], [], ['revision_id', 'required_template_id']);
        $this->domain('quest_instance', ['cycle_key' => $this->string(80)->notNull(), 'status' => $this->string(20)->notNull(), 'accepted_at' => $this->integer()->notNull(), 'expires_at' => $this->bigInteger(), 'closed_at' => $this->integer(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['actor_id' => ['game_actor'], 'owner_user_id' => ['user'], 'template_id' => ['quest_template'], 'quest_revision_id' => ['quest_revision'], 'scope_node_id' => ['world_node'], 'operation_id' => ['game_operation'], 'reward_commitment_id' => ['economy_spending_commitment', 'id', false]], [['actor_id', 'template_id', 'cycle_key'], ['operation_id']], [['owner_user_id', 'status', 'id']]);
        $this->domain('quest_objective_progress', ['quantity' => $this->integer()->notNull()->defaultValue(0), 'updated_at' => $this->integer()->notNull()], ['instance_id' => ['quest_instance'], 'objective_id' => ['quest_objective']], [], [], ['instance_id', 'objective_id']);
        $this->domain('quest_event_receipt', ['quantity' => $this->integer()->notNull(), 'processed_at' => $this->integer()->notNull()], ['instance_id' => ['quest_instance'], 'objective_id' => ['quest_objective'], 'event_id' => ['game_outbox']], [], [], ['instance_id', 'objective_id', 'event_id']);
        $this->domain('quest_claim', ['rewards_json' => $this->text()->notNull(), 'claimed_at' => $this->integer()->notNull()], ['instance_id' => ['quest_instance'], 'operation_id' => ['game_operation'], 'transfer_id' => ['economy_transfer', 'id', false]], [['operation_id']], [], ['instance_id']);
    }
}
