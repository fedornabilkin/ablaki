<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%craft_recipe_tool}}`.
 */
class m230317_195416_create_craft_recipe_tool_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_recipe_tool}}', [
            'id' => $this->primaryKey(),
            'recipe_id' => $this->integer()->unsigned()->notNull(),
            'item_id' => $this->integer()->unsigned()->notNull(),
        ]);

        $this->createIndex('{{%idx-craft_recipe_tool-recipe_id-item_id}}',
            '{{%craft_recipe_tool}}',
            ['item_id', 'recipe_id'],
            true
        );

        $this->addForeignKey('{{%fki-craft_recipe_tool-recipe_id-craft_recipe-id}}',
            '{{%craft_recipe_tool}}',
            'recipe_id',
            '{{%craft_recipe}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->addForeignKey('{{%fki-craft_recipe_tool-item_id-craft_item-id}}',
            '{{%craft_recipe_tool}}',
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
        $this->dropForeignKey('{{%fki-craft_recipe_tool-recipe_id-craft_recipe-id}}', '{{%craft_recipe_tool}}');
        $this->dropForeignKey('{{%fki-craft_recipe_tool-item_id-craft_item-id}}', '{{%craft_recipe_tool}}');

        $this->dropIndex('{{%idx-craft_recipe_tool-recipe_id-item_id}}', '{{%craft_recipe_tool}}');

        $this->dropTable('{{%craft_recipe_tool}}');
    }
}
