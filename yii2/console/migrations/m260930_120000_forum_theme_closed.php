<?php

use yii\db\Migration;

class m260930_120000_forum_theme_closed extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%forum_theme}}', 'is_closed', $this->boolean()->notNull()->defaultValue(false));
        $this->createIndex('idx-forum-theme-closed', '{{%forum_theme}}', 'is_closed');
    }

    public function safeDown()
    {
        $this->dropIndex('idx-forum-theme-closed', '{{%forum_theme}}');
        $this->dropColumn('{{%forum_theme}}', 'is_closed');
    }
}
