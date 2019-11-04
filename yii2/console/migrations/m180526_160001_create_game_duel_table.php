<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160001_create_game_duel_table
 */
class m180526_160001_create_game_duel_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%game_duel}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'user_gamer' => $this->bigInteger(),
            'kon' => $this->float()->notNull()->unsigned(),
            'u1' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'u2' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'b1' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'b2' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'updated_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-game_duel-kon}}','{{%game_duel}}','kon');
        $this->createIndex('{{%idx-game_duel-user_gamer}}','{{%game_duel}}','user_gamer');

        $this->addForeignKey('fki-game_duel-user_id-user-id',
            '{{%game_duel}}',
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
        $this->dropForeignKey('fki-game_duel-user_id-user-id', '{{%game_duel}}');

        $this->dropIndex('{{%idx-game_duel-kon}}','{{%game_duel}}');
        $this->dropIndex('{{%idx-game_duel-user_gamer}}','{{%game_duel}}');

        $this->dropTable('{{%game_duel}}');
    }
}