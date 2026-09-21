<?php
use yii\db\Migration;

class m260921_100000_craft_credit_switch extends Migration
{
    public function safeUp()
    {
        $this->addColumn('craft_meta','charge_credits',$this->smallInteger()->notNull()->defaultValue(0));
        $this->addColumn('craft_meta','charges_updated_by',$this->integer()->null());
        $this->addColumn('craft_meta','charges_updated_at',$this->integer()->null());
    }
    public function safeDown()
    {
        // Removing the switch could silently restore paid crafting in older code.
        return false;
    }
}
