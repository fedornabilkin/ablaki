<?php
use common\services\game\WorldDomainMigration;

class m260928_003100_world_content_and_extensions extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_change_set', ['reason' => $this->string(255)->notNull(), 'base_revision' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'digest' => $this->string(64)->notNull(), 'impact_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(), 'published_at' => $this->integer()],
            ['author_user_id' => ['user'], 'operation_id' => ['game_operation', 'id', false]], [['operation_id']], [['status', 'id']]);
        $this->domain('world_change_item', ['kind' => $this->string(24)->notNull(), 'code' => $this->string(80)->notNull(), 'base_version' => $this->integer()->notNull(), 'before_json' => $this->text()->notNull(), 'after_json' => $this->text()->notNull()], ['change_set_id' => ['world_change_set']], [['change_set_id', 'kind', 'code']]);
        // Existing garden entitlements have UNIQUE(node_id). Capacity rights need many ordinals per room.
        $this->domain('world_capacity_entitlement', ['ordinal' => $this->integer()->notNull(), 'price' => $this->decimal(19, 4)->notNull(), 'policy_revision' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()], ['policy_id' => ['world_expansion_policy'], 'node_id' => ['world_node'], 'transfer_id' => ['economy_transfer', 'id', false], 'operation_id' => ['game_operation']], [['policy_id', 'ordinal'], ['operation_id', 'ordinal']], [['node_id', 'id']]);
        $this->domain('world_room_extension', ['area' => $this->integer()->notNull(), 'terms_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull()], ['building_id' => ['world_node'], 'room_id' => ['world_node'], 'entitlement_id' => ['world_expansion_entitlement'], 'operation_id' => ['game_operation']], [['room_id'], ['entitlement_id']], [['building_id', 'id']]);
        $this->domain('world_capacity_place', ['kind' => $this->string(32)->notNull(), 'ordinal' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'rules_json' => $this->text()->notNull()],
            ['node_id' => ['world_node'], 'entitlement_id' => ['world_capacity_entitlement'], 'operation_id' => ['game_operation']], [['node_id', 'kind', 'ordinal'], ['entitlement_id']], [['node_id', 'status', 'id']]);
        $this->domain('world_forced_demolition', ['reason' => $this->string(255)->notNull(), 'before_json' => $this->text()->notNull(), 'result_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull()],
            ['node_id' => ['world_node'], 'admin_user_id' => ['user'], 'operation_id' => ['game_operation']], [['node_id'], ['operation_id']]);
        $this->domain('world_demolition_recovery', ['quantity' => $this->integer()->notNull()], ['demolition_id' => ['world_forced_demolition'], 'inventory_id' => ['craft_inventory'], 'source_storage_id' => ['craft_storage'], 'recovery_storage_id' => ['craft_storage']], [['demolition_id', 'inventory_id']]);
        // Extra beds reuse the existing housing intervals/claims, never a parallel lodging system.
        if (!isset($this->db->schema->getTableSchema('world_housing_place', true)->columns['ordinal'])) $this->addColumn('world_housing_place', 'ordinal', $this->integer()->notNull()->defaultValue(1));
        $this->index('ux_housing_room_ordinal', 'world_housing_place', ['room_id', 'ordinal'], true);
        $this->index('ix_housing_purchase', 'world_housing_place', ['purchase_id', 'id']);
        $this->index('ix_housing_operation', 'world_housing_place', ['operation_id', 'id']);
        foreach (['room_id', 'purchase_id', 'operation_id'] as $column) {
            foreach ($this->db->schema->getTableIndexes('world_housing_place', true) as $index) if ($index->name === 'ux_housing_place_' . $column) { $this->dropIndex($index->name, 'world_housing_place'); break; }
        }
        $this->domain('world_housing_extension', ['created_at' => $this->integer()->notNull()], ['place_id' => ['world_housing_place'], 'entitlement_id' => ['world_capacity_entitlement'], 'operation_id' => ['game_operation']], [['place_id'], ['entitlement_id']], [['operation_id']]);
    }
}
