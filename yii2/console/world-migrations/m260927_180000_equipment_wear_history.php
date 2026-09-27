<?php
use common\services\game\WorldMigration;
use yii\db\Query;

class m260927_180000_equipment_wear_history extends WorldMigration
{
    public function safeUp()
    {
        $this->table('craft_equipment_wear_history', [
            'id' => $this->primaryKey(), 'instance_id' => $this->reference('craft_equipment_instance') . ' NOT NULL',
            'owner_user_id' => $this->reference('user') . ' NOT NULL', 'kind' => $this->string(16)->notNull(),
            'started_at' => $this->integer()->notNull(), 'ended_at' => $this->integer()->notNull(),
            'durability_before' => $this->integer()->notNull(), 'durability_after' => $this->integer()->notNull(),
            'remainder_before' => $this->integer()->notNull(), 'remainder_after' => $this->integer()->notNull(),
            'class_before' => $this->string(16)->notNull(), 'class_after' => $this->string(16)->notNull(),
            'daily_wear_before' => $this->integer()->notNull(), 'daily_wear_after' => $this->integer()->notNull(),
            'policy_version' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('ix_equipment_wear_owner', 'craft_equipment_wear_history', ['owner_user_id', 'instance_id', 'id']);
        $this->index('ix_equipment_wear_kind', 'craft_equipment_wear_history', ['instance_id', 'kind', 'id']);
        $this->foreign('fk_equipment_wear_instance', 'craft_equipment_wear_history', 'instance_id', 'craft_equipment_instance');
        $this->foreign('fk_equipment_wear_owner', 'craft_equipment_wear_history', 'owner_user_id', 'user');
        $this->table('world_wear_scan', ['id' => $this->integer()->notNull(), 'after_instance_id' => $this->integer()->notNull()->defaultValue(0), 'PRIMARY KEY ([[id]])']);
        if (!(new Query())->from('world_wear_scan')->where(['id' => 1])->exists($this->db)) $this->insert('world_wear_scan', ['id' => 1, 'after_instance_id' => 0]);
    }
}
