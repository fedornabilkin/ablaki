<?php
use common\services\game\WorldMigration;

class m260927_107000_economy_parents extends WorldMigration
{
    public function safeUp()
    {
        $this->table('economy_parent_history', [
            'id' => $this->primaryKey(), 'subject_id' => $this->integer()->notNull(), 'parent_subject_id' => $this->integer(),
            'revision' => $this->integer()->notNull(), 'status' => $this->string(24)->notNull(),
            'basis' => $this->string(32)->notNull(), 'rate_bps' => $this->integer(), 'due_seconds' => $this->integer(),
            'effective_from' => $this->integer()->notNull(), 'effective_to' => $this->integer(),
            'operation_id' => $this->string(32)->notNull(), 'reason' => $this->string(255)->notNull(),
        ]);
        $this->index('ux_economy_parent_history_revision', 'economy_parent_history', ['subject_id', 'revision'], true);
        $this->index('idx_economy_parent_history_parent', 'economy_parent_history', ['parent_subject_id', 'effective_to']);
        $this->foreign('fk_economy_parent_history_subject', 'economy_parent_history', 'subject_id', 'economy_subject');
        $this->foreign('fk_economy_parent_history_parent', 'economy_parent_history', 'parent_subject_id', 'economy_subject');
        $this->foreign('fk_economy_parent_history_operation', 'economy_parent_history', 'operation_id', 'game_operation');
        $this->table('economy_parent_rule', [
            'subject_id' => $this->integer()->notNull(), 'history_id' => $this->integer()->notNull(),
            'revision' => $this->integer()->notNull(), 'PRIMARY KEY ([[subject_id]])',
        ]);
        $this->index('ux_economy_parent_current_history', 'economy_parent_rule', ['history_id'], true);
        $this->foreign('fk_economy_parent_rule_subject', 'economy_parent_rule', 'subject_id', 'economy_subject');
        $this->foreign('fk_economy_parent_rule_history', 'economy_parent_rule', 'history_id', 'economy_parent_history');
    }
}
