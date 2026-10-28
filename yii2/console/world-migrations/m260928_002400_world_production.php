<?php
use common\modules\world\support\WorldDomainMigration;

class m260928_002400_world_production extends WorldDomainMigration
{
    public function safeUp()
    {
        if (!$this->db->schema->getTableSchema('craft_storage', true)->getColumn('owner_actor_id')) $this->addColumn('craft_storage', 'owner_actor_id', $this->reference('game_actor') . ' NULL');
        $this->foreign('fk_craft_storage_owner_actor', 'craft_storage', 'owner_actor_id', 'game_actor');
        $this->index('idx_craft_storage_actor_status', 'craft_storage', ['owner_actor_id', 'status', 'id']);
        $owner = $this->db->schema->getTableSchema('craft_inventory', true)->getColumn('user_id');
        if (!$owner->allowNull) $this->alterColumn('craft_inventory', 'user_id', $owner->dbType . ' NULL');
        $this->domain('production_order', ['quantity' => $this->integer()->notNull(), 'status' => $this->string(20)->notNull(), 'recipe_snapshot_json' => $this->text()->notNull(), 'terms_json' => $this->text()->notNull(), 'started_at' => $this->integer()->notNull(), 'finish_at' => $this->bigInteger()->notNull(), 'closed_at' => $this->integer(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['owner_user_id' => ['user'], 'actor_id' => ['game_actor'], 'node_id' => ['world_node'], 'recipe_id' => ['craft_recipe'], 'assignment_id' => ['npc_assignment', 'id', false], 'output_storage_id' => ['craft_storage'], 'operation_id' => ['game_operation']], [['operation_id']], [['owner_user_id', 'status', 'id'], ['status', 'finish_at', 'id']]);
        $this->domain('inventory_reservation', ['quantity' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull(), 'released_at' => $this->integer()],
            ['order_id' => ['production_order'], 'inventory_id' => ['craft_inventory'], 'operation_id' => ['game_operation']], [['order_id', 'inventory_id']], [['inventory_id', 'released_at', 'id']]);
        $this->domain('equipment_reservation', ['created_at' => $this->integer()->notNull(), 'released_at' => $this->integer()],
            ['order_id' => ['production_order'], 'instance_id' => ['craft_equipment_instance'], 'operation_id' => ['game_operation']], [['order_id', 'instance_id']], [['instance_id', 'released_at', 'id']]);
        $this->domain('equipment_reservation_guard', [], ['instance_id' => ['craft_equipment_instance'], 'reservation_id' => ['equipment_reservation']], [['reservation_id']], [], ['instance_id']);
        $this->domain('production_actor_guard', [], ['actor_id' => ['game_actor'], 'order_id' => ['production_order']], [['order_id']], [], ['actor_id']);
        $this->domain('production_result', ['result_json' => $this->text()->notNull(), 'completed_at' => $this->integer()->notNull()], ['order_id' => ['production_order'], 'operation_id' => ['game_operation']], [['operation_id']], [], ['order_id']);
        $this->domain('production_output', ['quantity' => $this->integer()->notNull(), 'delivered_quantity' => $this->integer()->notNull()->defaultValue(0)], ['order_id' => ['production_order'], 'item_id' => ['craft_item']], [['order_id', 'item_id']]);
        $this->domain('production_delivery', ['quantity' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()], ['output_id' => ['production_output'], 'storage_id' => ['craft_storage'], 'operation_id' => ['game_operation']], [['output_id', 'operation_id']]);
        $this->domain('production_source', ['purpose' => $this->string(24)->notNull()], ['order_id' => ['production_order'], 'storage_id' => ['craft_storage']], [], [], ['order_id', 'storage_id', 'purpose']);
    }
}
