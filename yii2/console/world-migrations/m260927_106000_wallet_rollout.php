<?php
use common\modules\world\support\WorldMigration;

/** Snapshot storage only: the credit columns are changed by an explicit offline command. */
class m260927_106000_wallet_rollout extends WorldMigration
{
    public function safeUp()
    {
        $this->table('economy_wallet_rollout', [
            'id' => $this->integer()->notNull(), 'run_id' => $this->string(32)->notNull(),
            'phase' => $this->string(24)->notNull(), 'started_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(), 'PRIMARY KEY ([[id]])',
        ]);
        $this->table('economy_wallet_column', [
            'run_id' => $this->string(32)->notNull(), 'column_key' => $this->string(64)->notNull(),
            'original_type' => $this->string(255)->notNull(), 'target_definition' => $this->text()->notNull(),
            'phase' => $this->string(24)->notNull(), 'row_count' => $this->bigInteger()->notNull(),
            'last_source_id' => $this->bigInteger(),
            'raw_hash' => $this->string(64)->notNull(), 'exact_hash' => $this->string(64)->notNull(),
            'created_at' => $this->integer()->notNull(), 'updated_at' => $this->integer()->notNull(),
            'PRIMARY KEY ([[run_id]], [[column_key]])',
        ]);
        $this->table('economy_wallet_snapshot', [
            'run_id' => $this->string(32)->notNull(), 'column_key' => $this->string(64)->notNull(),
            'source_id' => $this->bigInteger()->notNull(), 'raw_amount' => $this->text()->notNull(),
            'exact_amount' => $this->decimal(19, 4)->notNull(),
            'PRIMARY KEY ([[run_id]], [[column_key]], [[source_id]])',
        ]);
        // No FK to mutable source rows: evidence must survive subsequent account cleanup.
    }
}
