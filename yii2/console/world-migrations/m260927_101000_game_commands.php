<?php
use common\services\game\WorldMigration;

class m260927_101000_game_commands extends WorldMigration
{
    public function safeUp()
    {
        $user = $this->reference('user');
        $this->table('game_operation', ['id' => $this->string(32)->notNull(), 'user_id' => $user, 'type' => $this->string(80)->notNull(), 'created_at' => $this->integer()->notNull(), 'PRIMARY KEY ([[id]])']);
        $this->foreign('fk_game_operation_user', 'game_operation', 'user_id', 'user');
        $this->foreign('fk_world_audit_operation', 'world_audit', 'operation_id', 'game_operation');
        $this->foreign('fk_world_construction_operation', 'world_construction', 'operation_id', 'game_operation');
        $this->table('game_command', [
            'id' => $this->primaryKey(), 'user_id' => $user . ' NOT NULL', 'request_key' => $this->db->driverName === 'mysql' ? 'varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL' : $this->string(80)->notNull(),
            'command_type' => $this->string(80)->notNull(), 'contract_version' => $this->integer()->notNull(), 'fingerprint' => $this->string(64)->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'result_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_game_command_key', 'game_command', ['user_id', 'request_key'], true);
        $this->index('idx_game_command_created', 'game_command', ['created_at', 'id']);
        $this->foreign('fk_game_command_user', 'game_command', 'user_id', 'user');
        $this->foreign('fk_game_command_operation', 'game_command', 'operation_id', 'game_operation');
        $this->table('game_quote', [
            'id' => $this->string(32)->notNull(), 'user_id' => $user . ' NOT NULL', 'command_type' => $this->string(80)->notNull(),
            'payload_json' => $this->text()->notNull(), 'terms_json' => $this->text()->notNull(), 'revisions_json' => $this->text()->notNull(),
            'expires_at' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull(), 'operation_id' => $this->string(32), 'PRIMARY KEY ([[id]])',
        ]);
        $this->index('idx_game_quote_expiry', 'game_quote', ['expires_at']);
        $this->index('idx_game_quote_user_created', 'game_quote', ['user_id', 'created_at']);
        $this->foreign('fk_game_quote_user', 'game_quote', 'user_id', 'user');
        $this->foreign('fk_game_quote_operation', 'game_quote', 'operation_id', 'game_operation');
        $this->table('game_outbox', [
            'id' => $this->string(32)->notNull(), 'operation_id' => $this->string(32)->notNull(), 'user_id' => $user,
            'event_type' => $this->string(80)->notNull(), 'event_version' => $this->integer()->notNull()->defaultValue(1),
            'payload_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(), 'dispatched_at' => $this->integer(), 'PRIMARY KEY ([[id]])',
        ]);
        $this->index('idx_game_outbox_pending', 'game_outbox', ['dispatched_at', 'created_at', 'id']);
        $this->foreign('fk_game_outbox_operation', 'game_outbox', 'operation_id', 'game_operation');
        $this->foreign('fk_game_outbox_user', 'game_outbox', 'user_id', 'user');
        $this->table('game_inbox', ['consumer' => $this->string(80)->notNull(), 'event_id' => $this->string(32)->notNull(), 'processed_at' => $this->integer()->notNull(), 'PRIMARY KEY ([[consumer]], [[event_id]])']);
        $this->foreign('fk_game_inbox_event', 'game_inbox', 'event_id', 'game_outbox');
        $this->table('game_job', [
            'id' => $this->primaryKey(), 'type' => $this->string(64)->notNull(), 'business_key' => $this->string(100)->notNull(),
            'owner_user_id' => $user, 'payload_json' => $this->text()->notNull(), 'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'available_at' => $this->integer()->notNull(), 'lease_until' => $this->integer(), 'fencing_token' => $this->integer()->notNull()->defaultValue(0),
            'attempts' => $this->integer()->notNull()->defaultValue(0), 'last_error_code' => $this->string(80), 'created_at' => $this->integer()->notNull(), 'finished_at' => $this->integer(),
        ]);
        $this->index('ux_game_job_business', 'game_job', ['type', 'business_key'], true);
        $this->index('idx_game_job_due', 'game_job', ['status', 'available_at', 'id']);
        $this->index('idx_game_job_lease', 'game_job', ['status', 'lease_until', 'id']);
        $this->foreign('fk_game_job_owner', 'game_job', 'owner_user_id', 'user');
        $this->db->schema->refresh();
    }
}
