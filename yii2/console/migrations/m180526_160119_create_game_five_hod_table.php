<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_160119_create_game_five_hod_table
 */
class m180526_160119_create_game_five_hod_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%game_five_hod}}', [
            'id' => $this->primaryKey(),
            'game_five_id' => $this->bigInteger(),
            'user_id' => $this->bigInteger(),
            'user_gamer' => $this->bigInteger(),
            'user_ball' => $this->smallInteger(1)->notNull()->unsigned(),
            'gamer_ball' => $this->smallInteger(1)->notNull()->unsigned(),
            'user_amount' => $this->integer(),
            'gamer_amount' => $this->integer(),
            'status' => $this->char(50),
            'created_at' => $this->integer()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-game_five_hod-status}}','{{%game_five_hod}}','status');
        $this->createIndex('{{%idx-game_five_hod-user_gamer}}','{{%game_five_hod}}','user_gamer');

        $this->addForeignKey('fki-game_five_hod-game_five_id-game_five-id',
            '{{%game_five_hod}}',
            'game_five_id',
            '{{%game_five}}',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->addForeignKey('fki-game_five_hod-user_id-user-id',
            '{{%game_five_hod}}',
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
        $this->dropForeignKey('fki-game_five_hod-game_five_id-game_five-id', '{{%game_five_hod}}');
        $this->dropForeignKey('fki-game_five_hod-user_id-user-id', '{{%game_five_hod}}');

        $this->dropIndex('{{%idx-game_five_hod-status}}','{{%game_five_hod}}');
        $this->dropIndex('{{%idx-game_five_hod-user_gamer}}','{{%game_five_hod}}');

        $this->dropTable('{{%game_five_hod}}');
    }
}