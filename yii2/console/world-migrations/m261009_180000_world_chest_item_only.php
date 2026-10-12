<?php
use yii\db\Migration;

/** Chests are inventory items; existing legacy object references remain valid. */
class m261009_180000_world_chest_item_only extends Migration
{
    public function safeUp()
    {
        $this->update('world_template', ['enabled' => 0, 'player_buildable' => 0], ['code' => 'chest']);
    }
    public function safeDown() { return false; }
}
