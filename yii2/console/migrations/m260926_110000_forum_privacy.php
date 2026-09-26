<?php
use yii\db\Migration;

class m260926_110000_forum_privacy extends Migration
{
    public function safeUp()
    {
        if (!$this->db->schema->getTableSchema('forum_theme', true)->getColumn('is_private')) {
            $this->addColumn('forum_theme', 'is_private', $this->smallInteger()->notNull()->defaultValue(0));
            $this->createIndex('idx-forum-theme-privacy', 'forum_theme', ['is_private', 'id']);
        }
        $this->db->schema->refresh();
    }
    public function safeDown() { return false; }
}
