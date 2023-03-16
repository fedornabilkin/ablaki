<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%forum_comment}}`.
 */
class m230213_192632_create_forum_comment_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%forum_comment}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'theme_id' => $this->bigInteger(),
            'comment' => $this->char(3000),
            'active' => $this->smallInteger(1)->defaultValue(1),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-forum_comment-user_id}}', '{{%forum_comment}}', 'user_id');
        $this->createIndex('{{%idx-forum_comment-theme_id}}', '{{%forum_comment}}', 'theme_id');

        $this->addForeignKey('fki-forum_comment-user_id-user-id',
            '{{%forum_comment}}',
            'user_id',
            '{{%user}}',
            'id',
            'RESTRICT',
            'RESTRICT'
        );

        $this->addForeignKey('fki-forum_comment-theme_id-forum_theme-id',
            '{{%forum_comment}}',
            'theme_id',
            '{{%forum_theme}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropForeignKey('fki-forum_comment-user_id-user-id', '{{%forum_comment}}');
        $this->dropForeignKey('fki-forum_comment-theme_id-forum_theme-id', '{{%forum_comment}}');

        $this->dropIndex('{{%idx-forum_comment-user_id}}', '{{%forum_comment}}');
        $this->dropIndex('{{%idx-forum_comment-theme_id}}', '{{%forum_comment}}');

        $this->dropTable('{{%forum_comment}}');
    }
}
