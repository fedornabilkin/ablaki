<?php
use common\modules\world\support\WorldDomainMigration;

class m261031_120000_world_presence extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_presence', [
            'user_id' => $this->integer()->notNull(), 'node_id' => $this->integer()->notNull(),
            'revision' => $this->integer()->notNull(), 'seen_at' => $this->integer()->notNull(),
        ], ['user_id' => ['user'], 'node_id' => ['world_node']], [], [['node_id', 'seen_at']], ['user_id']);
    }
    public function safeDown() { return false; }
}
