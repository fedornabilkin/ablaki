<?php
use common\services\game\WorldDomainMigration;

/** Journals only. Creating zero accounts is an explicit, resumable console operation. */
class m260928_003300_economy_account_backfill extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('economy_account_backfill_run', [
            'upper_node_id' => $this->integer()->notNull(), 'cursor_id' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->string(16)->notNull(), 'processed_count' => $this->integer()->notNull()->defaultValue(0),
            'created_accounts' => $this->integer()->notNull()->defaultValue(0), 'skipped_count' => $this->integer()->notNull()->defaultValue(0),
            'started_at' => $this->integer()->notNull(), 'updated_at' => $this->integer()->notNull(), 'completed_at' => $this->integer(),
        ], ['world_id' => ['world_node'], 'active_world_id' => ['world_node', 'id', false]], [['active_world_id']], [['world_id', 'id']]);
        $this->domain('economy_account_backfill_item', [
            'outcome' => $this->string(32)->notNull(), 'created_accounts' => $this->integer()->notNull(),
            'before_json' => $this->db->driverName === 'mysql' ? 'MEDIUMTEXT NOT NULL' : $this->text()->notNull(),
            'after_json' => $this->db->driverName === 'mysql' ? 'MEDIUMTEXT NOT NULL' : $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ], ['run_id' => ['economy_account_backfill_run'], 'node_id' => ['world_node'], 'operation_id' => ['game_operation', 'id', false]], [['run_id', 'node_id'], ['operation_id']], [['node_id', 'id']]);
    }
}
