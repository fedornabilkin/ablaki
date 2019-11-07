<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160606_create_payments_table
 */
class m180526_160606_create_payments_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%payments}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'amount' => $this->double()->notNull()->unsigned(),
            'wmz' => $this->char(13),
            'paysystem' => $this->char(255),
            'type' => $this->char(50),
            'status' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'buy_sell' => $this->char(50),
            'comment' => $this->string(500),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-payments-type}}','{{%payments}}','type');
        $this->createIndex('{{%idx-payments-status}}','{{%payments}}','status');

        $this->addForeignKey('fki-payments-user_id-user-id',
            '{{%payments}}',
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
        $this->dropForeignKey('fki-payments-user_id-user-id', '{{%payments}}');

        $this->dropIndex('{{%idx-payments-type}}','{{%payments}}');
        $this->dropIndex('{{%idx-payments-status}}','{{%payments}}');

        $this->dropTable('{{%payments}}');
    }
}
