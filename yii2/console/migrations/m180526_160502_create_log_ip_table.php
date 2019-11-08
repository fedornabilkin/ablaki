<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160502_create_log_ip_table
 */
class m180526_160502_create_log_ip_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%log_ip}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'ip' => $this->char(15),
            'agent' => $this->char(255),
            'comment' => $this->char(255),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-log_ip-ip}}','{{%log_ip}}','ip');

        $this->addForeignKey('fki-log_ip-user_id-user-id',
            '{{%log_ip}}',
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
        $this->dropForeignKey('fki-log_ip-user_id-user-id', '{{%log_ip}}');

        $this->dropIndex('{{%idx-log_ip-ip}}','{{%log_ip}}');

        $this->dropTable('{{%log_ip}}');
    }
}