<?php
use yii\db\Migration;

class m260926_100000_craft_repair extends Migration
{
    public function safeUp()
    {
        if (!$this->db->schema->getTableSchema('craft_tool_wear', true)) {
            $this->createTable('craft_tool_wear', [
                'user_id' => $this->integer()->notNull(),
                'item_id' => $this->integer()->notNull(),
                'wear' => $this->integer()->notNull()->defaultValue(0),
                'PRIMARY KEY ([[user_id]], [[item_id]])',
            ]);
        }
        $this->db->schema->refresh();
    }
    public function safeDown() { return false; }
}
