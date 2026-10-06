<?php
use common\modules\world\support\WorldMigration;

class m260927_160000_equipment_expansion extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_equipment_expansion', [
            'policy_id' => $this->reference('world_expansion_policy') . ' NOT NULL PRIMARY KEY',
            'storage_id' => $this->reference('craft_storage') . ' NOT NULL',
            'recipient_node_id' => $this->reference('world_node') . ' NOT NULL',
            'template_revision_id' => $this->reference('world_template_revision') . ' NOT NULL',
        ]);
        $this->index('ux_equipment_expansion_storage', 'world_equipment_expansion', ['storage_id'], true);
        $this->table('world_slot_entitlement', [
            'id' => $this->primaryKey(), 'policy_id' => $this->reference('world_expansion_policy') . ' NOT NULL',
            'storage_id' => $this->reference('craft_storage') . ' NOT NULL', 'position' => $this->reference('world_slot', 'position') . ' NOT NULL',
            'price' => $this->decimal(19, 4)->notNull(), 'policy_revision' => $this->integer()->notNull(),
            'transfer_id' => $this->reference('economy_transfer'), 'operation_id' => $this->reference('game_operation') . ' NOT NULL',
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_slot_entitlement_slot', 'world_slot_entitlement', ['storage_id', 'position'], true);
        $this->index('ux_slot_entitlement_ordinal', 'world_slot_entitlement', ['policy_id', 'position'], true);
        $this->index('ux_slot_entitlement_operation', 'world_slot_entitlement', ['operation_id', 'position'], true);
        $n = 0;
        foreach ([
            'world_equipment_expansion' => ['policy_id' => 'world_expansion_policy', 'storage_id' => 'craft_storage', 'recipient_node_id' => 'world_node', 'template_revision_id' => 'world_template_revision'],
            'world_slot_entitlement' => ['policy_id' => 'world_expansion_policy', 'transfer_id' => 'economy_transfer', 'operation_id' => 'game_operation'],
        ] as $table => $columns) foreach ($columns as $column => $parent) $this->foreign('fk_equipment_expansion_' . ++$n, $table, $column, $parent);
        if ($this->db->driverName !== 'sqlite') {
            foreach ($this->db->schema->getTableForeignKeys('world_slot_entitlement', true) as $key) if ($key->name === 'fk_slot_entitlement_place') return;
            $this->addForeignKey('fk_slot_entitlement_place', 'world_slot_entitlement', ['storage_id', 'position'], 'world_slot', ['storage_id', 'position'], 'RESTRICT', 'RESTRICT');
        }
    }
}
