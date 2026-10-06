<?php
use common\modules\world\support\WorldMigration;

/** Nullable expansion only. Canonical reading is enabled separately after backfill. */
class m260927_102000_world_storage extends WorldMigration
{
    public function safeUp()
    {
        $user = $this->reference('user'); $inventory = $this->reference('craft_inventory'); $item = $this->reference('craft_item');
        $this->table('craft_storage', [
            'id' => $this->primaryKey(), 'identity_key' => $this->string(120)->notNull(), 'kind' => $this->string(20)->notNull(),
            'owner_user_id' => $user, 'node_id' => $this->integer(), 'container_inventory_id' => $inventory,
            'capacity' => $this->integer()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1), 'status' => $this->string(20)->notNull()->defaultValue('active'),
        ]);
        $this->index('ux_craft_storage_identity', 'craft_storage', ['identity_key'], true);
        $this->index('ux_craft_storage_container', 'craft_storage', ['container_inventory_id'], true);
        $this->index('idx_craft_storage_owner', 'craft_storage', ['owner_user_id', 'kind', 'id']);
        $this->index('idx_craft_storage_node', 'craft_storage', ['node_id', 'kind', 'id']);
        $this->foreign('fk_craft_storage_owner', 'craft_storage', 'owner_user_id', 'user');
        $this->foreign('fk_craft_storage_node', 'craft_storage', 'node_id', 'world_node');
        $this->foreign('fk_craft_storage_container', 'craft_storage', 'container_inventory_id', 'craft_inventory');
        foreach (['storage_id' => $this->integer(), 'revision' => $this->integer()->notNull()->defaultValue(1)] as $field => $type) {
            if (!$this->db->schema->getTableSchema('craft_inventory', true)->getColumn($field)) $this->addColumn('craft_inventory', $field, $type);
        }
        $this->index('idx_craft_inventory_storage', 'craft_inventory', ['storage_id', 'item_id', 'id']);
        $this->foreign('fk_craft_inventory_storage', 'craft_inventory', 'storage_id', 'craft_storage');
        $this->table('world_slot', [
            'storage_id' => $this->integer()->notNull(), 'position' => $this->integer()->notNull(), 'code' => $this->string(64)->notNull(),
            'slot_type' => $this->string(24)->notNull(), 'size' => $this->integer()->notNull()->defaultValue(1), 'exposure_class' => $this->string(16)->notNull()->defaultValue('outdoor'),
            'compatibility_json' => $this->text()->notNull(), 'status' => $this->string(16)->notNull()->defaultValue('active'), 'PRIMARY KEY ([[storage_id]], [[position]])',
        ]);
        $this->index('ux_world_slot_code', 'world_slot', ['storage_id', 'code'], true);
        $this->foreign('fk_world_slot_storage', 'world_slot', 'storage_id', 'craft_storage');
        $this->table('craft_equipment_instance', [
            'id' => $this->primaryKey(), 'inventory_id' => $inventory, 'origin_inventory_id' => $inventory . ' NOT NULL', 'unit_ordinal' => $this->integer()->notNull(),
            'item_id' => $item . ' NOT NULL', 'purpose' => $this->string(16)->notNull()->defaultValue('equipment'), 'status' => $this->string(16)->notNull()->defaultValue('active'),
            'durability' => $this->integer()->notNull()->defaultValue(100), 'max_durability' => $this->integer()->notNull()->defaultValue(100),
            'quality' => $this->integer()->notNull()->defaultValue(1), 'revision' => $this->integer()->notNull()->defaultValue(1),
        ]);
        $this->index('ux_craft_instance_origin', 'craft_equipment_instance', ['origin_inventory_id', 'unit_ordinal'], true);
        $this->index('idx_craft_instance_inventory', 'craft_equipment_instance', ['inventory_id', 'status', 'id']);
        $this->foreign('fk_craft_instance_inventory', 'craft_equipment_instance', 'inventory_id', 'craft_inventory');
        $this->foreign('fk_craft_instance_origin', 'craft_equipment_instance', 'origin_inventory_id', 'craft_inventory');
        $this->foreign('fk_craft_instance_item', 'craft_equipment_instance', 'item_id', 'craft_item');
        $this->table('craft_wear_assignment', ['user_id' => $user . ' NOT NULL', 'item_id' => $item . ' NOT NULL', 'instance_id' => $this->integer(), 'wear' => $this->integer()->notNull(), 'applied_at' => $this->integer(), 'PRIMARY KEY ([[user_id]], [[item_id]])']);
        $this->foreign('fk_craft_wear_assignment_user', 'craft_wear_assignment', 'user_id', 'user');
        $this->foreign('fk_craft_wear_assignment_item', 'craft_wear_assignment', 'item_id', 'craft_item');
        $this->foreign('fk_craft_wear_assignment_instance', 'craft_wear_assignment', 'instance_id', 'craft_equipment_instance');
        $this->table('inventory_movement', [
            'id' => $this->primaryKey(), 'operation_id' => $this->string(32)->notNull(), 'inventory_id' => $inventory . ' NOT NULL', 'item_id' => $item . ' NOT NULL',
            'source_storage_id' => $this->integer(), 'destination_storage_id' => $this->integer(), 'quantity' => $this->integer()->notNull(), 'reason' => $this->string(40)->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('idx_inventory_movement_operation', 'inventory_movement', ['operation_id', 'id']);
        $this->index('idx_inventory_movement_inventory', 'inventory_movement', ['inventory_id', 'id']);
        $this->foreign('fk_inventory_movement_operation', 'inventory_movement', 'operation_id', 'game_operation');
        $this->foreign('fk_inventory_movement_inventory', 'inventory_movement', 'inventory_id', 'craft_inventory');
        $this->foreign('fk_inventory_movement_item', 'inventory_movement', 'item_id', 'craft_item');
        foreach (['source_storage_id', 'destination_storage_id'] as $field) $this->foreign('fk_inventory_movement_' . $field, 'inventory_movement', $field, 'craft_storage');
        $this->table('world_backfill_checkpoint', ['code' => $this->string(64)->notNull(), 'cursor_id' => $user . ' NOT NULL', 'processed_count' => $this->integer()->notNull()->defaultValue(0), 'status' => $this->string(20)->notNull()->defaultValue('pending'), 'updated_at' => $this->integer()->notNull(), 'PRIMARY KEY ([[code]])']);
        $this->db->schema->refresh();
    }
}
