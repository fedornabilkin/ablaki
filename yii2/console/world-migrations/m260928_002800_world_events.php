<?php
use common\services\game\WorldDomainMigration;

class m260928_002800_world_events extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_event', ['status' => $this->string(16)->notNull(), 'seed' => $this->string(64)->notNull(), 'starts_at' => $this->bigInteger()->notNull(), 'ends_at' => $this->bigInteger(), 'closed_at' => $this->integer(), 'rules_json' => $this->text()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['scope_node_id' => ['world_node'], 'template_revision_id' => ['world_template_revision'], 'operation_id' => ['game_operation']], [['operation_id']], [['scope_node_id', 'status', 'id'], ['status', 'ends_at', 'id']]);
        $this->domain('world_event_effect', ['effect_code' => $this->string(64)->notNull(), 'value' => $this->integer()->notNull(), 'stacking' => $this->string(16)->notNull(), 'rules_json' => $this->text()->notNull()], ['event_id' => ['world_event']], [['event_id', 'effect_code']]);
        $this->domain('world_event_application', ['before_json' => $this->text()->notNull(), 'after_json' => $this->text()->notNull(), 'applied_at' => $this->integer()->notNull()], ['effect_id' => ['world_event_effect'], 'target_node_id' => ['world_node'], 'tick_id' => ['world_tick'], 'operation_id' => ['game_operation']], [['effect_id', 'target_node_id', 'tick_id']], [['target_node_id', 'id']]);
        $this->domain('world_event_action', ['action_code' => $this->string(64)->notNull(), 'result_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull()], ['event_id' => ['world_event'], 'actor_id' => ['game_actor'], 'node_id' => ['world_node'], 'operation_id' => ['game_operation']], [['operation_id']], [['event_id', 'id']]);
        $this->domain('world_event_actor_effect', ['effect_code' => $this->string(64)->notNull(), 'values_json' => $this->text()->notNull(), 'effective_from' => $this->bigInteger()->notNull(), 'effective_to' => $this->bigInteger()], ['event_id' => ['world_event'], 'actor_id' => ['game_actor']], [['event_id', 'actor_id', 'effect_code']], [['actor_id', 'effective_to']]);
    }
}
