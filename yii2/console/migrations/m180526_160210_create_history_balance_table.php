<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160210_create_history_balance_table
 */
class m180526_160210_create_history_balance_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%history_balance}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'balance' => $this->double()->defaultValue(0)->unsigned(),
            'credit' => $this->double()->defaultValue(0)->unsigned(),
            'balance_up' => $this->double()->defaultValue(0),
            'credit_up' => $this->double()->defaultValue(0),
            'type' => $this->char(50),
            'comment' => $this->char(255),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-history_balance-type}}','{{%history_balance}}','type');

        $this->addForeignKey('fki-history_balance-user_id-user-id',
            '{{%history_balance}}',
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
        $this->dropForeignKey('fki-history_balance-user_id-user-id', '{{%history_balance}}');

        $this->dropIndex('{{%idx-history_balance-type}}','{{%history_balance}}');

        $this->dropTable('{{%history_balance}}');
    }
}
