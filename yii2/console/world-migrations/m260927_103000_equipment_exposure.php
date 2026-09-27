<?php
use common\services\game\WorldMigration;

class m260927_103000_equipment_exposure extends WorldMigration
{
    public function safeUp()
    {
        $this->table('craft_equipment_exposure', [
            'instance_id' => $this->integer()->notNull(), 'exposure_class' => $this->string(16)->notNull(),
            'daily_wear' => $this->integer()->notNull()->defaultValue(0), 'settled_at' => $this->integer()->notNull(),
            'remainder' => $this->integer()->notNull()->defaultValue(0), 'policy_version' => $this->integer()->notNull()->defaultValue(1),
            'PRIMARY KEY ([[instance_id]])',
        ]);
        $this->foreign('fk_craft_exposure_instance', 'craft_equipment_exposure', 'instance_id', 'craft_equipment_instance');
        $this->index('idx_craft_exposure_due', 'craft_equipment_exposure', ['daily_wear', 'settled_at']);
        $this->db->schema->refresh();
    }
}
