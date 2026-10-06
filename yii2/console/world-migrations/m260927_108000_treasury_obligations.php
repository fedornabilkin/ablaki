<?php
use common\modules\world\support\WorldMigration;

/** Additive, restartable DDL. Rates deliberately have no seeded balancing values. */
class m260927_108000_treasury_obligations extends WorldMigration
{
    public function safeUp()
    {
        $this->table('economy_collection_policy', [
            'history_id' => $this->integer()->notNull(), 'protected_seconds' => $this->integer()->notNull(),
            'loss_period_seconds' => $this->integer()->notNull(), 'loss_rate_bps' => $this->integer()->notNull(),
            'PRIMARY KEY ([[history_id]])',
        ]);
        $this->foreign('fk_economy_policy_history', 'economy_collection_policy', 'history_id', 'economy_parent_history');
        $this->table('economy_tax_checkpoint', ['history_id' => $this->integer()->notNull(), 'fraction' => $this->integer()->notNull()->defaultValue(0), 'PRIMARY KEY ([[history_id]])']);
        $this->foreign('fk_economy_tax_history', 'economy_tax_checkpoint', 'history_id', 'economy_parent_history');
        $this->table('economy_treasury_receipt', [
            'id' => $this->primaryKey(), 'transfer_id' => $this->integer()->notNull(), 'account_id' => $this->integer()->notNull(),
            'history_id' => $this->integer()->notNull(), 'original_amount' => $this->decimal(19, 4)->notNull(),
            'remaining_amount' => $this->decimal(19, 4)->notNull(), 'received_at' => $this->integer()->notNull(),
            'protected_until' => $this->integer()->notNull(), 'next_loss_at' => $this->bigInteger()->notNull(),
            'loss_sequence' => $this->integer()->notNull()->defaultValue(0), 'loss_fraction' => $this->integer()->notNull()->defaultValue(0),
            'closed_at' => $this->integer(),
        ]);
        $this->index('ux_economy_receipt_transfer', 'economy_treasury_receipt', ['transfer_id'], true);
        $this->index('idx_economy_receipt_fifo', 'economy_treasury_receipt', ['account_id', 'closed_at', 'received_at', 'id']);
        $this->foreign('fk_economy_receipt_transfer', 'economy_treasury_receipt', 'transfer_id', 'economy_transfer');
        $this->foreign('fk_economy_receipt_account', 'economy_treasury_receipt', 'account_id', 'economy_account');
        $this->foreign('fk_economy_receipt_policy', 'economy_treasury_receipt', 'history_id', 'economy_collection_policy', 'history_id');
        $this->table('economy_treasury_loss', [
            'id' => $this->primaryKey(), 'receipt_id' => $this->integer()->notNull(), 'sequence' => $this->integer()->notNull(),
            'period_at' => $this->bigInteger()->notNull(), 'amount' => $this->decimal(19, 4)->notNull(),
            'fraction_after' => $this->integer()->notNull(), 'transfer_id' => $this->integer(), 'operation_id' => $this->string(32)->notNull(),
        ]);
        $this->index('ux_economy_loss_period', 'economy_treasury_loss', ['receipt_id', 'sequence'], true);
        $this->foreign('fk_economy_loss_receipt', 'economy_treasury_loss', 'receipt_id', 'economy_treasury_receipt');
        $this->foreign('fk_economy_loss_transfer', 'economy_treasury_loss', 'transfer_id', 'economy_transfer');
        $this->foreign('fk_economy_loss_operation', 'economy_treasury_loss', 'operation_id', 'game_operation');
        $this->table('economy_receipt_collection', [
            'receipt_id' => $this->integer()->notNull(), 'transfer_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 4)->notNull(), 'PRIMARY KEY ([[receipt_id]], [[transfer_id]])',
        ]);
        $this->foreign('fk_economy_collection_receipt', 'economy_receipt_collection', 'receipt_id', 'economy_treasury_receipt');
        $this->foreign('fk_economy_collection_transfer', 'economy_receipt_collection', 'transfer_id', 'economy_transfer');
        $this->table('economy_obligation', [
            'id' => $this->primaryKey(), 'collection_transfer_id' => $this->integer()->notNull(), 'history_id' => $this->integer()->notNull(),
            'budget_account_id' => $this->integer()->notNull(), 'recipient_account_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 4)->notNull(), 'status' => $this->string(16)->notNull(),
            'created_at' => $this->integer()->notNull(), 'due_at' => $this->integer()->notNull(),
            'paid_at' => $this->integer(), 'payment_transfer_id' => $this->integer(),
        ]);
        $this->index('ux_economy_obligation_collection', 'economy_obligation', ['collection_transfer_id'], true);
        $this->index('ux_economy_obligation_payment', 'economy_obligation', ['payment_transfer_id'], true);
        $this->index('idx_economy_obligation_budget', 'economy_obligation', ['budget_account_id', 'status', 'id']);
        foreach (['collection_transfer_id' => 'economy_transfer', 'history_id' => 'economy_parent_history', 'budget_account_id' => 'economy_account', 'recipient_account_id' => 'economy_account', 'payment_transfer_id' => 'economy_transfer'] as $column => $parent) $this->foreign('fk_economy_obligation_' . $column, 'economy_obligation', $column, $parent);
        $this->table('economy_budget_reservation', [
            'obligation_id' => $this->integer()->notNull(), 'account_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 4)->notNull(), 'released_at' => $this->integer(), 'PRIMARY KEY ([[obligation_id]])',
        ]);
        $this->foreign('fk_economy_reservation_obligation', 'economy_budget_reservation', 'obligation_id', 'economy_obligation');
        $this->foreign('fk_economy_reservation_account', 'economy_budget_reservation', 'account_id', 'economy_account');
    }
}
