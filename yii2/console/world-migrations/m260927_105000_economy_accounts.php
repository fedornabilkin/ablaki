<?php
use common\modules\world\support\WorldMigration;

/** Additive only. Never silently round/ALTER existing personal balances on a deploy. */
class m260927_105000_economy_accounts extends WorldMigration
{
    public function safeUp()
    {
        $this->table('economy_registry', ['id' => $this->integer()->notNull(), 'wallet_ready' => $this->boolean()->notNull()->defaultValue(false), 'PRIMARY KEY ([[id]])']);
        if (!(new \yii\db\Query())->from('economy_registry')->where(['id' => 1])->exists($this->db)) $this->insert('economy_registry', ['id' => 1, 'wallet_ready' => false]);
        $this->table('economy_subject', ['id' => $this->primaryKey(), 'node_id' => $this->integer(), 'actor_id' => $this->integer(), 'asset_instance_id' => $this->integer(), 'created_at' => $this->integer()->notNull()]);
        foreach (['node_id' => 'world_node', 'actor_id' => 'game_actor', 'asset_instance_id' => 'craft_equipment_instance'] as $column => $parent) {
            $this->index('ux_economy_subject_' . $column, 'economy_subject', [$column], true);
            $this->foreign('fk_economy_subject_' . $column, 'economy_subject', $column, $parent);
        }
        $this->table('economy_account', ['id' => $this->primaryKey(), 'subject_id' => $this->integer()->notNull(), 'role' => $this->string(16)->notNull(), 'currency' => $this->string(8)->notNull()->defaultValue('Cr'),
            'amount' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'), 'reserved' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'), 'revision' => $this->integer()->notNull()->defaultValue(1)]);
        $this->index('ux_economy_account_role', 'economy_account', ['subject_id', 'role', 'currency'], true);
        $this->foreign('fk_economy_account_subject', 'economy_account', 'subject_id', 'economy_subject');
        $this->table('economy_transfer', ['id' => $this->primaryKey(), 'operation_id' => $this->string(32)->notNull(), 'line_code' => $this->string(64)->notNull(), 'kind' => $this->string(32)->notNull(),
            'source_account_id' => $this->integer(), 'source_user_id' => $this->reference('user'), 'destination_account_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 4)->notNull(), 'source_after' => $this->decimal(19, 4)->notNull(), 'destination_after' => $this->decimal(19, 4)->notNull(),
            'purpose' => $this->string(255)->notNull(), 'created_at' => $this->integer()->notNull()]);
        $this->index('ux_economy_transfer_operation_line', 'economy_transfer', ['operation_id', 'line_code'], true);
        $this->index('idx_economy_transfer_destination', 'economy_transfer', ['destination_account_id', 'id']);
        $this->index('idx_economy_transfer_source', 'economy_transfer', ['source_account_id', 'id']);
        $this->foreign('fk_economy_transfer_operation', 'economy_transfer', 'operation_id', 'game_operation');
        $this->foreign('fk_economy_transfer_source', 'economy_transfer', 'source_account_id', 'economy_account');
        $this->foreign('fk_economy_transfer_destination', 'economy_transfer', 'destination_account_id', 'economy_account');
        $this->foreign('fk_economy_transfer_user', 'economy_transfer', 'source_user_id', 'user');
        $this->table('economy_funding_lot', ['id' => $this->primaryKey(), 'transfer_id' => $this->integer()->notNull(), 'budget_account_id' => $this->integer()->notNull(), 'investor_user_id' => $this->reference('user') . ' NOT NULL',
            'original_amount' => $this->decimal(19, 4)->notNull(), 'remaining_amount' => $this->decimal(19, 4)->notNull(), 'purpose' => $this->string(255)->notNull(), 'created_at' => $this->integer()->notNull()]);
        $this->index('ux_economy_funding_transfer', 'economy_funding_lot', ['transfer_id'], true);
        $this->foreign('fk_economy_funding_transfer', 'economy_funding_lot', 'transfer_id', 'economy_transfer');
        $this->foreign('fk_economy_funding_budget', 'economy_funding_lot', 'budget_account_id', 'economy_account');
        $this->foreign('fk_economy_funding_investor', 'economy_funding_lot', 'investor_user_id', 'user');
    }
}
