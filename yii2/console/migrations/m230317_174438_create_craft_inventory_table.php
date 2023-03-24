<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `{{%craft_inventory}}`.
 */
class m230317_174438_create_craft_inventory_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%craft_inventory}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'item_id' => $this->integer()->unsigned(),
            'item_quantity' => $this->integer()->unsigned()->defaultValue(0),
            'slot' => $this->integer()->unsigned(),
        ]);

        $this->createIndex('{{%idx-craft_inventory-user_id}}', '{{%craft_inventory}}', 'user_id');
        $this->createIndex('{{%idx-craft_inventory-item_id}}', '{{%craft_inventory}}', 'item_id');

        $this->addForeignKey('{{%fki-craft_inventory-user_id-user-id}}',
            '{{%craft_inventory}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->addForeignKey('{{%fki-craft_inventory-item_id-craft_item-id}}',
            '{{%craft_inventory}}',
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

        $this->dropForeignKey('{{%fki-craft_inventory-user_id-user-id}}', '{{%craft_inventory}}');
        $this->dropForeignKey('{{%fki-craft_inventory-item_id-craft_item-id}}', '{{%craft_inventory}}');

        $this->dropIndex('{{%idx-craft_inventory-user_id}}', '{{%craft_inventory}}');
        $this->dropIndex('{{%idx-craft_inventory-item_id}}', '{{%craft_inventory}}');

        $this->dropTable('{{%craft_inventory}}');
    }
}
