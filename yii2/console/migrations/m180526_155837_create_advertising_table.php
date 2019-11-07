<?php

use console\migrations\AbstractMigration;
use yii\db\Migration;

/**
 * Class m180526_155837_create_advertising_table
 */
class m180526_155837_create_advertising_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%advertising}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer(),
            'title' => $this->char(70),
            'description' => $this->char(255),
            'approve' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'status' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'url' => $this->char(255),
            'banner' => $this->char(255),
            'position' => $this->char(50),
            'hash' => $this->char(32),
            'credit' => $this->decimal()->defaultValue(0)->unsigned(),
            'type' => $this->char(50),
            'clicks' => $this->bigInteger()->defaultValue(0)->unsigned(),
            'views' => $this->bigInteger()->defaultValue(0)->unsigned(),
            'comment' => $this->string(1000),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-advertising-approve}}','{{%advertising}}','approve');
        $this->createIndex('{{%idx-advertising-status}}','{{%advertising}}','status');
        $this->createIndex('{{%idx-advertising-credit}}','{{%advertising}}','credit');
        $this->createIndex('{{%idx-advertising-position}}','{{%advertising}}','position');

        $this->addForeignKey('fki-advertising-user_id-user-id',
            '{{%advertising}}',
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
        $this->dropForeignKey('fki-advertising-user_id-user-id', '{{%advertising}}');

        $this->dropIndex('{{%idx-advertising-approve}}','{{%advertising}}');
        $this->dropIndex('{{%idx-advertising-status}}','{{%advertising}}');
        $this->dropIndex('{{%idx-advertising-credit}}','{{%advertising}}');
        $this->dropIndex('{{%idx-advertising-position}}','{{%advertising}}');

        $this->dropTable('{{%advertising}}');
    }
}