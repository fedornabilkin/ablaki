<?php
use common\modules\world\support\WorldDomainMigration;

/** Additive preparation only: installing the schema never activates prices or admission. */
class m261017_120000_world_economy_profiles extends WorldDomainMigration
{
    public function safeUp()
    {
        if (!$this->db->schema->getTableSchema('game_operation', true)->getColumn('context_json'))
            $this->addColumn('game_operation', 'context_json', $this->text());
        // Transfers can be cancelled and deleted; retain their IDs without a FK to credit_transfer.
        $this->domain('credit_transfer_history', ['history_id' => $this->integer()->notNull(),
            'transfer_id' => $this->integer()->notNull(), 'phase' => $this->string(16)->notNull()],
            ['history_id' => ['history_balance']], [['history_id', 'transfer_id', 'phase']], [['transfer_id']]);
        $this->domain('world_economy_profile', [
            'code' => $this->string(80)->notNull(), 'version' => $this->integer()->notNull(),
            'config_json' => $this->text()->notNull(), 'catalog_before_json' => $this->text()->notNull(),
            'digest' => $this->string(64)->notNull(), 'reason' => $this->string(500)->notNull(),
            'author_user_id' => $this->integer(), 'created_at' => $this->integer()->notNull(),
        ], ['author_user_id' => ['user', 'id', false]], [['version']]);
        $this->domain('world_economy_current', ['id' => $this->integer()->notNull(),
            'profile_id' => $this->integer()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1)], ['profile_id' => ['world_economy_profile']], [], [], ['id']);
        $this->domain('world_test_participant', ['user_id' => $this->integer()->notNull(),
            'cohort' => $this->string(80)->notNull(), 'enabled' => $this->smallInteger()->notNull()->defaultValue(1),
            'author_user_id' => $this->integer(), 'created_at' => $this->integer()->notNull()], ['user_id' => ['user'], 'author_user_id' => ['user', 'id', false]], [], [], ['user_id']);
        $this->domain('world_economy_usage', ['world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'kind' => $this->string(40)->notNull(),
            'quantity' => $this->integer()->notNull(), 'gross_cr' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'),
            'profile_id' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()],
            ['world_id' => ['world_node'], 'user_id' => ['user'], 'operation_id' => ['game_operation'], 'profile_id' => ['world_economy_profile']], [['operation_id', 'kind']], [['world_id', 'user_id', 'kind', 'created_at']]);
        $this->domain('world_economy_fact', ['world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'code' => $this->string(80)->notNull(), 'quantity' => $this->integer()->notNull()->defaultValue(0),
            'first_operation_id' => $this->string(32)->notNull(), 'first_at' => $this->integer()->notNull()],
            ['world_id' => ['world_node'], 'user_id' => ['user'], 'first_operation_id' => ['game_operation']], [['world_id', 'user_id', 'code']]);
        $this->domain('world_economy_fact_event', ['world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'code' => $this->string(80)->notNull(), 'quantity' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'profile_id' => $this->integer()->notNull(), 'cohort' => $this->string(80), 'created_at' => $this->integer()->notNull()],
            ['world_id' => ['world_node'], 'user_id' => ['user'], 'operation_id' => ['game_operation'], 'profile_id' => ['world_economy_profile']], [['operation_id', 'code']], [['world_id', 'user_id', 'created_at']]);
        $this->domain('world_source_work', ['user_id' => $this->integer()->notNull(), 'node_id' => $this->integer()->notNull(),
            'active_key' => $this->string(80), 'action_code' => $this->string(80)->notNull(), 'definition_json' => $this->text()->notNull(),
            'profile_id' => $this->integer()->notNull(), 'target_storage_id' => $this->integer()->notNull(),
            'worked_seconds' => $this->integer()->notNull()->defaultValue(0), 'status' => $this->string(16)->notNull(),
            'blocked_code' => $this->string(80), 'blocked_message' => $this->string(500),
            'created_at' => $this->integer()->notNull(), 'completed_at' => $this->integer(), 'operation_id' => $this->string(32)],
            ['user_id' => ['user'], 'node_id' => ['world_node'], 'profile_id' => ['world_economy_profile'], 'target_storage_id' => ['craft_storage'], 'operation_id' => ['game_operation', 'id', false]], [['active_key']], [['user_id', 'node_id', 'id']]);
        // Same worker guard as Mining and BuildWork, including fresh legacy fixtures.
        $this->domain('world_mining_worker', ['accounted_at' => $this->integer()->notNull(), 'last_seen_at' => $this->integer()->notNull()],
            ['user_id' => ['user'], 'node_id' => ['world_node']], [], [['node_id']], ['user_id']);
        if (!$this->db->schema->getTableSchema('world_mining_worker', true)->getColumn('source_work_id'))
            $this->addColumn('world_mining_worker', 'source_work_id', $this->reference('world_source_work'));
        $this->foreign('fk_world_mining_worker_source_work', 'world_mining_worker', 'source_work_id', 'world_source_work');
        $this->domain('world_player_health', ['world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'hp' => $this->integer()->notNull()->defaultValue(100), 'revision' => $this->integer()->notNull()->defaultValue(1)], ['world_id' => ['world_node'], 'user_id' => ['user']], [['world_id', 'user_id']]);
        $this->domain('world_health_event', ['world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'hp_before' => $this->integer()->notNull(), 'hp_after' => $this->integer()->notNull(),
            'kind' => $this->string(80)->notNull(), 'profile_id' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()],
            ['world_id' => ['world_node'], 'user_id' => ['user'], 'operation_id' => ['game_operation'], 'profile_id' => ['world_economy_profile']], [['operation_id']], [['world_id', 'user_id', 'created_at']]);
        $this->domain('world_goal_pool', ['world_id' => $this->integer()->notNull(), 'settlement_id' => $this->integer()->notNull(),
            'goal_code' => $this->string(80)->notNull(), 'profile_id' => $this->integer()->notNull(), 'definition_json' => $this->text()->notNull(),
            'commitment_id' => $this->integer()->notNull(), 'remaining_claims' => $this->integer()->notNull(), 'created_by' => $this->integer()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'created_at' => $this->integer()->notNull()],
            ['world_id' => ['world_node'], 'settlement_id' => ['world_node'], 'profile_id' => ['world_economy_profile'], 'commitment_id' => ['economy_spending_commitment'], 'created_by' => ['user'], 'operation_id' => ['game_operation']], [['operation_id']], [['settlement_id', 'goal_code', 'id']]);
        $this->domain('world_goal_claim', ['world_id' => $this->integer()->notNull(), 'user_id' => $this->integer()->notNull(),
            'goal_code' => $this->string(80)->notNull(), 'pool_id' => $this->integer()->notNull(), 'operation_id' => $this->string(32)->notNull(),
            'earned_cr' => $this->decimal(19, 4)->notNull(), 'created_at' => $this->integer()->notNull()],
            ['world_id' => ['world_node'], 'user_id' => ['user'], 'pool_id' => ['world_goal_pool'], 'operation_id' => ['game_operation']], [['world_id', 'user_id', 'goal_code']], [['user_id', 'created_at']]);
        $this->domain('world_storage_expansion', ['code' => $this->string(80)->notNull(), 'capacity_before' => $this->integer()->notNull(), 'capacity_after' => $this->integer()->notNull(),
            'price_cr' => $this->decimal(19, 4)->notNull(), 'created_at' => $this->integer()->notNull()],
            ['user_id' => ['user'], 'storage_id' => ['craft_storage'], 'profile_id' => ['world_economy_profile'], 'operation_id' => ['game_operation']], [['operation_id']], [['user_id', 'created_at']]);
    }
}
