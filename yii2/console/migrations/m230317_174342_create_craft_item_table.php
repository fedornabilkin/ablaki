<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%craft_item}}`.
 */
class m230317_174342_create_craft_item_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_item}}', [
            'id' => $this->primaryKey(),
            'name' => $this->char(50)->notNull(),
            'description' => $this->char(5000),
            'crafting_time' => $this->integer()->unsigned(),
            'rare' => $this->smallInteger(3)->unsigned(),
            'category_id' => $this->integer()->unsigned()->notNull(),
            'active' => $this->smallInteger(1)->notNull()->defaultValue(0),
        ]);

        $this->createIndex('{{%idx-craft_item-name}}', '{{%craft_item}}', 'name', true);
        $this->createIndex('{{%idx-craft_item-category_id}}', '{{%craft_item}}', 'category_id');
        $this->createIndex('{{%idx-craft_item-active}}', '{{%craft_item}}', 'active');

        $this->addForeignKey('{{%fki-craft_item-category_id-craft_category-id}}',
            '{{%craft_item}}',
            'category_id',
            '{{%craft_category}}',
            'id',
            'RESTRICT',
            'RESTRICT'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropForeignKey('{{%fki-craft_item-category_id-craft_category-id}}', '{{%craft_item}}');

        $this->dropIndex('{{%idx-craft_item-name}}', '{{%craft_item}}');
        $this->dropIndex('{{%idx-craft_item-category_id}}', '{{%craft_item}}');
        $this->dropIndex('{{%idx-craft_item-active}}', '{{%craft_item}}');

        $this->dropTable('{{%craft_item}}');
    }
}
