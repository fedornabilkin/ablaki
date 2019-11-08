<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160807_create_credit_transfer_table
 */
class m180526_160807_create_credit_transfer_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%credit_transfer}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'recepient' => $this->bigInteger(),
            'amount' => $this->integer()->notNull()->unsigned(),
            'password' => $this->char(60),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-credit_transfer-recepient}}','{{%credit_transfer}}','recepient');

        $this->addForeignKey('fki-credit_transfer-user_id-user-id',
            '{{%credit_transfer}}',
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
        $this->dropForeignKey('fki-credit_transfer-user_id-user-id', '{{%credit_transfer}}');

        $this->dropIndex('{{%idx-credit_transfer-recepient}}','{{%credit_transfer}}');

        $this->dropTable('{{%credit_transfer}}');
    }
}