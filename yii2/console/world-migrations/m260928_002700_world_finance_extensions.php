<?php
use common\modules\world\support\WorldDomainMigration;

class m260928_002700_world_finance_extensions extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('economy_collect_batch', ['created_at' => $this->integer()->notNull(), 'terms_json' => $this->text()->notNull()], ['user_id' => ['user'], 'operation_id' => ['game_operation']], [['operation_id']], [['user_id', 'id']]);
        $this->domain('economy_collect_batch_item', ['amount' => $this->decimal(19, 4)->notNull()], ['batch_id' => ['economy_collect_batch'], 'node_id' => ['world_node'], 'transfer_id' => ['economy_transfer', 'id', false]], [['batch_id', 'node_id'], ['transfer_id']]);
        $this->domain('economy_budget_grant', ['purpose' => $this->string(255)->notNull(), 'amount' => $this->decimal(19, 4)->notNull(), 'created_at' => $this->integer()->notNull()],
            ['source_account_id' => ['economy_account'], 'destination_account_id' => ['economy_account'], 'parent_history_id' => ['economy_parent_history'], 'operation_id' => ['game_operation'], 'transfer_id' => ['economy_transfer']], [['operation_id'], ['transfer_id']], [['destination_account_id', 'id']]);
        $this->domain('economy_grant_allocation', ['amount' => $this->decimal(19, 4)->notNull()], ['grant_id' => ['economy_budget_grant'], 'funding_lot_id' => ['economy_funding_lot']], [], [], ['grant_id', 'funding_lot_id']);
        $this->domain('treasury_protection_interval', ['effective_from' => $this->bigInteger()->notNull(), 'effective_to' => $this->bigInteger(), 'protection_bps' => $this->integer()->notNull(), 'rules_json' => $this->text()->notNull()],
            ['account_id' => ['economy_account'], 'template_revision_id' => ['world_template_revision'], 'operation_id' => ['game_operation']], [['account_id', 'effective_from'], ['operation_id']], [['account_id', 'effective_to']]);
        $this->domain('treasury_protection_current', [], ['account_id' => ['economy_account'], 'interval_id' => ['treasury_protection_interval']], [['interval_id']], [], ['account_id']);
        $this->domain('economy_parent_change', ['reason' => $this->string(255)->notNull(), 'created_at' => $this->integer()->notNull()], ['subject_id' => ['economy_subject'], 'previous_history_id' => ['economy_parent_history'], 'new_history_id' => ['economy_parent_history'], 'operation_id' => ['game_operation']], [['new_history_id'], ['operation_id']], [['subject_id', 'id']]);
        $this->domain('economy_contract', ['kind' => $this->string(32)->notNull(), 'status' => $this->string(16)->notNull(), 'quantity' => $this->integer()->notNull(), 'remaining_quantity' => $this->integer()->notNull(), 'unit_price' => $this->decimal(19, 4)->notNull(), 'terms_json' => $this->text()->notNull(), 'expires_at' => $this->bigInteger(), 'revision' => $this->integer()->notNull()->defaultValue(1)],
            ['payer_account_id' => ['economy_account'], 'payee_account_id' => ['economy_account'], 'item_id' => ['craft_item', 'id', false], 'commitment_id' => ['economy_spending_commitment'], 'operation_id' => ['game_operation']], [['operation_id']], [['status', 'expires_at', 'id']]);
        $this->domain('economy_contract_settlement', ['quantity' => $this->integer()->notNull(), 'amount' => $this->decimal(19, 4)->notNull(), 'business_key' => $this->string(120)->notNull(), 'created_at' => $this->integer()->notNull()],
            ['contract_id' => ['economy_contract'], 'operation_id' => ['game_operation'], 'transfer_id' => ['economy_transfer']], [['contract_id', 'business_key'], ['transfer_id']], [['operation_id']]);
        $this->domain('economy_debt', ['amount' => $this->decimal(19, 4)->notNull(), 'remaining_amount' => $this->decimal(19, 4)->notNull(), 'due_at' => $this->bigInteger()->notNull(), 'status' => $this->string(16)->notNull(), 'created_at' => $this->integer()->notNull()],
            ['debtor_account_id' => ['economy_account'], 'creditor_account_id' => ['economy_account'], 'contract_id' => ['economy_contract', 'id', false], 'operation_id' => ['game_operation']], [['operation_id']], [['debtor_account_id', 'status', 'due_at']]);
        $this->domain('economy_debt_payment', ['amount' => $this->decimal(19, 4)->notNull(), 'paid_at' => $this->integer()->notNull()], ['debt_id' => ['economy_debt'], 'transfer_id' => ['economy_transfer'], 'operation_id' => ['game_operation']], [['transfer_id'], ['operation_id']]);
        $this->domain('npc_wage_accrual', ['amount' => $this->decimal(19, 4)->notNull(), 'status' => $this->string(16)->notNull(), 'terms_json' => $this->text()->notNull()],
            ['assignment_id' => ['npc_assignment'], 'tick_id' => ['world_tick'], 'budget_account_id' => ['economy_account'], 'recipient_account_id' => ['economy_account'], 'debt_id' => ['economy_debt', 'id', false], 'transfer_id' => ['economy_transfer', 'id', false]], [['assignment_id', 'tick_id']], [['budget_account_id', 'status', 'id']]);
        $this->domain('economy_snapshot', ['version' => $this->integer()->notNull(), 'totals_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull()], ['node_id' => ['world_node'], 'tick_id' => ['world_tick']], [['node_id', 'tick_id', 'version']]);
    }
}
