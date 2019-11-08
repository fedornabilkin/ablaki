<?php

/**
 * Handles the creation of table `bonus`.
 */
class m180722_194940_create_bonus_table extends \console\migrations\AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%bonus}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'amount' => $this->decimal(),
            'type' => $this->string(50),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
        ], $this->tableOptions);

        $this->addForeignKey('fki-bonus-user_id-user-id',
            '{{%bonus}}',
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
        $this->dropForeignKey('fki-bonus-user_id-user-id', '{{%bonus}}');
        $this->dropTable('{{%bonus}}');
    }
}
