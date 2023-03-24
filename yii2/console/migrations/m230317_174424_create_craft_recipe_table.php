<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%craft_recipe}}`.
 */
class m230317_174424_create_craft_recipe_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_recipe}}', [
            'id' => $this->primaryKey(),
            'name' => $this->char(50)->notNull(),
            'description' => $this->char(5000),
            'category_id' => $this->integer()->unsigned()->notNull(),
            'item_id' => $this->integer()->unsigned()->notNull(),
            'active' => $this->smallInteger(1)->defaultValue(0),
        ]);

        $this->createIndex('{{%idx-craft_recipe-name}}', '{{%craft_recipe}}', 'name', true);
        $this->createIndex('{{%idx-craft_recipe-category_id}}', '{{%craft_recipe}}', 'category_id');
        $this->createIndex('{{%idx-craft_recipe-item_id}}', '{{%craft_recipe}}', 'item_id', true);
        $this->createIndex('{{%idx-craft_recipe-active}}', '{{%craft_recipe}}', 'active');

        $this->addForeignKey('{{%fki-craft_recipe-category_id-craft_category-id}}',
            '{{%craft_recipe}}',
            'category_id',
            '{{%craft_category}}',
            'id',
            'RESTRICT',
            'RESTRICT'
        );

        $this->addForeignKey('{{%fki-craft_recipe-item_id-craft_item-id}}',
            '{{%craft_recipe}}',
            'item_id',
            '{{%craft_item}}',
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
        $this->dropForeignKey('{{%fki-craft_recipe-category_id-craft_category-id}}', '{{%craft_recipe}}');
        $this->dropForeignKey('{{%fki-craft_recipe-item_id-craft_item-id}}', '{{%craft_recipe}}');

        $this->dropIndex('{{%idx-craft_recipe-name}}', '{{%craft_recipe}}');
        $this->dropIndex('{{%idx-craft_recipe-category_id}}', '{{%craft_recipe}}');
        $this->dropIndex('{{%idx-craft_recipe-item_id}}', '{{%craft_recipe}}');
        $this->dropIndex('{{%idx-craft_recipe-active}}', '{{%craft_recipe}}');

        $this->dropTable('{{%craft_recipe}}');
    }
}
