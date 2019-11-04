<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160058_create_fact_table
 */
class m180526_160058_create_fact_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%fact}}', [
            'id' => $this->primaryKey(),
            'title' => $this->char(255),
            'type' => $this->char(50),
            'hide' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-fact-hide}}','{{%fact}}','hide');

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropIndex('{{%idx-fact-hide}}','{{%fact}}');

        $this->dropTable('{{%fact}}');
    }
}