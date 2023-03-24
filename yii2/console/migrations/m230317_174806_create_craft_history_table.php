<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%craft_history}}`.
 */
class m230317_174806_create_craft_history_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_history}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'item_id' => $this->integer()->unsigned()->notNull(),
            'recipe_id' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ]);

        $this->createIndex('{{%idx-craft_history-user_id}}', '{{%craft_history}}', 'user_id');
        $this->createIndex('{{%idx-craft_history-item_id}}', '{{%craft_history}}', 'item_id');
        $this->createIndex('{{%idx-craft_history-recipe_id}}', '{{%craft_history}}', 'recipe_id');

        $this->addForeignKey('{{%fki-craft_history-user_id-user-id}}',
            '{{%craft_history}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->addForeignKey('{{%fki-craft_history-item_id-craft_item-id}}',
            '{{%craft_history}}',
            'item_id',
            '{{%craft_item}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->addForeignKey('{{%fki-craft_history-recipe_id-craft_recipe-id}}',
            '{{%craft_history}}',
            'recipe_id',
            '{{%craft_recipe}}',
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
        $this->dropForeignKey('{{%fki-craft_history-user_id-user-id}}', '{{%craft_history}}');
        $this->dropForeignKey('{{%fki-craft_history-item_id-craft_item-id}}', '{{%craft_history}}');
        $this->dropForeignKey('{{%fki-craft_history-recipe_id-craft_recipe-id}}', '{{%craft_history}}');

        $this->dropIndex('{{%idx-craft_history-user_id}}', '{{%craft_history}}');
        $this->dropIndex('{{%idx-craft_history-item_id}}', '{{%craft_history}}');
        $this->dropIndex('{{%idx-craft_history-recipe_id}}', '{{%craft_history}}');

        $this->dropTable('{{%craft_history}}');
    }
}
