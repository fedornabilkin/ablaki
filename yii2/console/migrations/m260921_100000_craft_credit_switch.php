<?php
use common\modules\world\support\WorldMigration;

class m260921_100000_craft_credit_switch extends WorldMigration
{
    public function safeUp()
    {
        foreach (['charge_credits' => $this->smallInteger()->notNull()->defaultValue(0), 'charges_updated_by' => $this->integer()->null(), 'charges_updated_at' => $this->integer()->null()] as $name => $type)
            if (!$this->db->schema->getTableSchema('craft_meta', true)->getColumn($name)) $this->addColumn('craft_meta', $name, $type);
    }
    public function safeDown()
    {
        // Removing the switch could silently restore paid crafting in older code.
        return false;
    }
}
