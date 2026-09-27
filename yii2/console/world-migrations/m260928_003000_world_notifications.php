<?php
use common\services\game\WorldDomainMigration;

class m260928_003000_world_notifications extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('game_notification', ['notification_type' => $this->string(64)->notNull(), 'title' => $this->string(160)->notNull(), 'body' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(), 'read_at' => $this->integer()],
            ['user_id' => ['user'], 'source_event_id' => ['game_outbox'], 'node_id' => ['world_node', 'id', false]], [['user_id', 'source_event_id', 'notification_type']], [['user_id', 'read_at', 'id']]);
        $this->domain('notification_preference', ['notification_type' => $this->string(64)->notNull(), 'channel' => $this->string(16)->notNull(), 'enabled' => $this->boolean()->notNull()->defaultValue(true), 'quiet_start_minute' => $this->integer(), 'quiet_end_minute' => $this->integer(), 'timezone' => $this->string(64)->notNull()->defaultValue('Europe/Moscow'), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['user_id' => ['user']], [], [], ['user_id', 'notification_type', 'channel']);
    }
}
