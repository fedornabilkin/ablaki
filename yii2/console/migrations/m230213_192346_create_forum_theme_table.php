<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%forum_theme}}`.
 */
class m230213_192346_create_forum_theme_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%forum_theme}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'title' => $this->char(250),
            'view' => $this->integer()->notNull()->unsigned(),
            'last_post' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-forum_theme-user_id}}', '{{%forum_theme}}', 'user_id');

        $this->addForeignKey('fki-forum_theme-user_id-user-id',
            '{{%forum_theme}}',
            'user_id',
            '{{%user}}',
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
        $this->dropForeignKey('fki-forum_theme-user_id-user-id', '{{%forum_theme}}');

        $this->dropIndex('{{%idx-forum_theme-user_id}}', '{{%forum_theme}}');

        $this->dropTable('{{%forum_theme}}');
    }
}
