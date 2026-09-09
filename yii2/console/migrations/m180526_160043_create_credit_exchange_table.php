<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160043_create_credit_exchange_table
 */
class m180526_160043_create_credit_exchange_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%credit_exchange}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'user_buyer' => $this->bigInteger(),
            'credit' => $this->double()->notNull()->unsigned(),
            'amount' => $this->double()->notNull()->unsigned(),
            'type' => $this->char(50),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-credit_exchange-type}}','{{%credit_exchange}}','type');
        $this->createIndex('{{%idx-credit_exchange-user_buyer}}','{{%credit_exchange}}','user_buyer');

        $this->addForeignKey('fki-credit_exchange-user_id-user-id',
            '{{%credit_exchange}}',
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
        $this->dropForeignKey('fki-credit_exchange-user_id-user-id', '{{%credit_exchange}}');

        $this->dropIndex('{{%idx-credit_exchange-type}}','{{%credit_exchange}}');
        $this->dropIndex('{{%idx-credit_exchange-user_buyer}}','{{%credit_exchange}}');

        $this->dropTable('{{%credit_exchange}}');
    }
}
