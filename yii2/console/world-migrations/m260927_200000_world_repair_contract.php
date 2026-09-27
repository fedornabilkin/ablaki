<?php
use common\services\game\WorldMigration;

/** New offers and accepted supplements; original purchase terms are never rewritten. */
class m260927_200000_world_repair_contract extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_repair_offer', [
            'offer_id' => $this->reference('world_premises_offer') . ' NOT NULL',
            'kind' => $this->string(16)->notNull(), 'area' => $this->integer()->notNull(), 'PRIMARY KEY ([[offer_id]])',
        ]);
        $this->index('ix_repair_offer_match', 'world_repair_offer', ['kind', 'area', 'offer_id']);
        $this->foreign('fk_repair_offer_source', 'world_repair_offer', 'offer_id', 'world_premises_offer');
        $this->table('world_repair_contract', [
            'id' => $this->primaryKey(), 'building_id' => $this->reference('world_node') . ' NOT NULL',
            'purchase_id' => $this->reference('world_premises_purchase') . ' NOT NULL',
            'offer_id' => $this->reference('world_premises_offer') . ' NOT NULL',
            'user_id' => $this->reference('user') . ' NOT NULL',
            'operation_id' => $this->reference('game_operation') . ' NOT NULL',
            'terms_json' => $this->text()->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        foreach (['building_id', 'purchase_id', 'operation_id'] as $column) $this->index('ux_repair_contract_' . $column, 'world_repair_contract', [$column], true);
        foreach (['building_id' => 'world_node', 'purchase_id' => 'world_premises_purchase', 'offer_id' => 'world_premises_offer', 'user_id' => 'user', 'operation_id' => 'game_operation'] as $column => $parent) $this->foreign('fk_repair_contract_' . $column, 'world_repair_contract', $column, $parent);
    }
}
