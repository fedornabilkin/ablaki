<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_155944_create_comission_table
 */
class m180526_155944_create_comission_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%comission}}', [
            'id' => $this->primaryKey(),
            'type' => $this->char(50),
            'amount' => $this->double()->notNull()->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-comission-type}}', '{{%comission}}', 'type');
        $this->createIndex('{{%idx-comission-created_at}}', '{{%comission}}', 'created_at');


    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {

        $this->dropIndex('{{%idx-comission-type}}', '{{%comission}}');
        $this->dropIndex('{{%idx-comission-created_at}}', '{{%comission}}');

        $this->dropTable('{{%comission}}');
    }
}
