<?php

use yii\db\Migration;

class m260905_120000_create_forum_comment_gift_table extends Migration
{
    public function safeUp()
    {
        $userId = $this->db->getTableSchema('{{%user}}', true)->getColumn('id')->dbType . ' NOT NULL';
        $commentId = $this->db->getTableSchema('{{%forum_comment}}', true)->getColumn('id')->dbType . ' NOT NULL';
        $options = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB' : null;
        $this->createTable('{{%forum_comment_gift}}', [
            'id' => $this->primaryKey(),
            'comment_id' => $commentId,
            'user_id' => $userId,
            'recipient_id' => $userId,
            'created_at' => $this->integer()->notNull(),
        ], $options);
        $this->createIndex('uq-forum-gift-comment-user', '{{%forum_comment_gift}}', ['comment_id', 'user_id'], true);
        $this->createIndex('idx-forum-gift-user', '{{%forum_comment_gift}}', 'user_id');
        $this->createIndex('idx-forum-gift-recipient', '{{%forum_comment_gift}}', 'recipient_id');
        $this->addForeignKey('fk-forum-gift-comment', '{{%forum_comment_gift}}', 'comment_id', '{{%forum_comment}}', 'id', 'RESTRICT', 'RESTRICT');
        $this->addForeignKey('fk-forum-gift-user', '{{%forum_comment_gift}}', 'user_id', '{{%user}}', 'id', 'RESTRICT', 'RESTRICT');
        $this->addForeignKey('fk-forum-gift-recipient', '{{%forum_comment_gift}}', 'recipient_id', '{{%user}}', 'id', 'RESTRICT', 'RESTRICT');
    }

    public function safeDown()
    {
        // Financial audit records must not be removed by an automatic rollback.
        if ((new \yii\db\Query())->from('{{%forum_comment_gift}}')->exists($this->db)) return false;
        $this->dropTable('{{%forum_comment_gift}}');
    }
}
