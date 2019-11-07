<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160250_create_history_rating_table
 */
class m180526_160250_create_history_rating_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%history_rating}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'rating' => $this->double()->notNull(),
            'type' => $this->char(50),
            'comment' => $this->char(255),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-history_rating-type}}','{{%history_rating}}','type');

        $this->addForeignKey('fki-history_rating-user_id-user-id',
            '{{%history_rating}}',
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
        $this->dropForeignKey('fki-history_rating-user_id-user-id', '{{%history_rating}}');

        $this->dropIndex('{{%idx-history_rating-type}}','{{%history_rating}}');

        $this->dropTable('{{%history_rating}}');
    }
}
