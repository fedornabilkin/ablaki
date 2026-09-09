<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%craft_category}}`.
 */
class m230317_174340_create_craft_category_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_category}}', [
            'id' => $this->primaryKey(),
            'name' => $this->char(50)->notNull(),
            'description' => $this->char(5000),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%craft_category}}');
    }
}
