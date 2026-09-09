<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160515_create_game_orel_table
 */
class m180526_160515_create_game_orel_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%game_orel}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'user_gamer' => $this->bigInteger(),
            'kon' => $this->integer()->notNull()->unsigned(),
            'type' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'hod' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-game_orel-kon}}','{{%game_orel}}','kon');
        $this->createIndex('{{%idx-game_orel-hod}}','{{%game_orel}}','hod');
        $this->createIndex('{{%idx-game_orel-user_gamer}}','{{%game_orel}}','user_gamer');

        $this->addForeignKey('fki-game_orel-user_id-user-id',
            '{{%game_orel}}',
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
        $this->dropForeignKey('fki-game_orel-user_id-user-id', '{{%game_orel}}');

        $this->dropIndex('{{%idx-game_orel-kon}}','{{%game_orel}}');
        $this->dropIndex('{{%idx-game_orel-hod}}','{{%game_orel}}');
        $this->dropIndex('{{%idx-game_orel-user_gamer}}','{{%game_orel}}');

        $this->dropTable('{{%game_orel}}');
    }
}