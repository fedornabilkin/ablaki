<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160016_create_log_errors_table
 */
class m180526_160016_create_log_errors_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%log_errors}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'type' => $this->char(50),
            'comment' => $this->string(1000),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%log_errors}}');
    }
}