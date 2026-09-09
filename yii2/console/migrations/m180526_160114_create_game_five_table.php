<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160114_create_game_five_table
 */
class m180526_160114_create_game_five_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%game_five}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'user_gamer' => $this->bigInteger(),
            'user_amount' => $this->integer(),
            'gamer_amount' => $this->integer(),
            'kon' => $this->float()->notNull()->unsigned(),
            'status' => $this->char(50),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-game_five-kon}}','{{%game_five}}','kon');
        $this->createIndex('{{%idx-game_five-status}}','{{%game_five}}','status');
        $this->createIndex('{{%idx-game_five-user_gamer}}','{{%game_five}}','user_gamer');

        $this->addForeignKey('fki-game_five-user_id-user-id',
            '{{%game_five}}',
            'user_id',
            '{{%user}}',
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
        $this->dropForeignKey('fki-game_five-user_id-user-id', '{{%game_five}}');

        $this->dropIndex('{{%idx-game_five-kon}}','{{%game_five}}');
        $this->dropIndex('{{%idx-game_five-status}}','{{%game_five}}');
        $this->dropIndex('{{%idx-game_five-user_gamer}}','{{%game_five}}');

        $this->dropTable('{{%game_five}}');
    }
}