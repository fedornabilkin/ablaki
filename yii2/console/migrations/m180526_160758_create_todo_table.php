<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160758_create_todo_table
 */
class m180526_160758_create_todo_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%todo}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'title' => $this->char(50),
            'comment' => $this->text(),
            'status' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%todo}}');
    }
}
