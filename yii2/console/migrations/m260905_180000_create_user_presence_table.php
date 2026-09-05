<?php

use yii\db\Migration;

class m260905_180000_create_user_presence_table extends Migration
{
    public function safeUp()
    {
        // Imported installations can use BIGINT/unsigned keys; MySQL requires an exact FK type match.
        $userIdType = $this->db->getTableSchema('{{%user}}', true)->getColumn('id')->dbType;
        $this->createTable('{{%user_presence}}', [
            'user_id' => $userIdType . ' NOT NULL',
            'last_seen_at' => $this->integer()->notNull(),
            'PRIMARY KEY ([[user_id]])',
        ], $this->db->driverName === 'mysql' ? 'ENGINE=InnoDB' : null);
        $this->createIndex('idx-user_presence-last_seen_at', '{{%user_presence}}', 'last_seen_at');
        $this->addForeignKey('fk-user_presence-user', '{{%user_presence}}', 'user_id', '{{%user}}', 'id', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%user_presence}}');
    }
}
