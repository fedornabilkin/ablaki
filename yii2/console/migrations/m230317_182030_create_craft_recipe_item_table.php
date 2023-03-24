<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%craft_recipe_item}}`.
 */
class m230317_182030_create_craft_recipe_item_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_recipe_item}}', [
            'id' => $this->primaryKey(),
            'recipe_id' => $this->integer()->unsigned()->notNull(),
            'item_id' => $this->integer()->unsigned()->notNull(),
            'item_quantity' => $this->integer()->unsigned()->notNull()->defaultValue(1),
        ]);

        $this->createIndex('{{%idx-craft_recipe_item-recipe_id-item_id}}',
            '{{%craft_recipe_item}}',
            ['item_id', 'recipe_id'],
            true
        );

        $this->addForeignKey('{{%fki-craft_recipe_item-recipe_id-craft_recipe-id}}',
            '{{%craft_recipe_item}}',
            'recipe_id',
            '{{%craft_recipe}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->addForeignKey('{{%fki-craft_recipe_item-item_id-craft_item-id}}',
            '{{%craft_recipe_item}}',
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
        $this->dropForeignKey('{{%fki-craft_recipe_item-recipe_id-craft_recipe-id}}', '{{%craft_recipe_item}}');
        $this->dropForeignKey('{{%fki-craft_recipe_item-item_id-craft_item-id}}', '{{%craft_recipe_item}}');

        $this->dropIndex('{{%idx-craft_recipe_item-recipe_id-item_id}}', '{{%craft_recipe_item}}');

        $this->dropTable('{{%craft_recipe_item}}');
    }
}
