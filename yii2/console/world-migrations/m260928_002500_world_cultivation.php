<?php
use common\services\game\WorldDomainMigration;

class m260928_002500_world_cultivation extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_crop', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull()], [], [['code']]);
        $this->domain('world_crop_revision', ['version' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'seed_quantity' => $this->integer()->notNull(), 'yield_quantity' => $this->integer()->notNull(), 'grow_seconds' => $this->integer()->notNull(), 'water_quantity' => $this->integer()->notNull(), 'water_interval_seconds' => $this->integer()->notNull(), 'config_json' => $this->text()->notNull(), 'published_at' => $this->integer()],
            ['crop_id' => ['world_crop'], 'seed_item_id' => ['craft_item'], 'yield_item_id' => ['craft_item'], 'water_item_id' => ['craft_item', 'id', false], 'author_user_id' => ['user', 'id', false]], [['crop_id', 'version']], [['status', 'id']]);
        $this->domain('world_crop_cycle', ['status' => $this->string(16)->notNull(), 'planted_at' => $this->integer()->notNull(), 'ready_at' => $this->bigInteger()->notNull(), 'water_due_at' => $this->bigInteger(), 'paused_at' => $this->integer(), 'closed_at' => $this->integer(), 'terms_json' => $this->text()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['bed_id' => ['world_bed', 'node_id'], 'owner_user_id' => ['user'], 'crop_revision_id' => ['world_crop_revision'], 'operation_id' => ['game_operation']], [['operation_id']], [['owner_user_id', 'status', 'id'], ['status', 'ready_at', 'id']]);
        $this->domain('world_bed_active_cycle', [], ['bed_id' => ['world_bed', 'node_id'], 'cycle_id' => ['world_crop_cycle']], [['cycle_id']], [], ['bed_id']);
        $this->domain('world_crop_action', ['kind' => $this->string(24)->notNull(), 'quantity' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()], ['cycle_id' => ['world_crop_cycle'], 'operation_id' => ['game_operation']], [['operation_id']], [['cycle_id', 'id']]);
        $this->domain('world_harvest_result', ['quantity' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()], ['cycle_id' => ['world_crop_cycle'], 'item_id' => ['craft_item'], 'storage_id' => ['craft_storage'], 'operation_id' => ['game_operation']], [['operation_id']], [], ['cycle_id']);
    }
}
