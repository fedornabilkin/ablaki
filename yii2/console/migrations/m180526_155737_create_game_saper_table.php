<?php

use console\migrations\AbstractMigration;

/**
 * Class m180526_155737_create_game_saper_table
 */
class m180526_155737_create_game_saper_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%game_saper}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'user_gamer' => $this->bigInteger()->defaultValue(0),
            'kon' => $this->float()->notNull()->unsigned(),
            'kon_double' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'etap' => $this->smallInteger(2)->defaultValue(5)->unsigned(),
            'pole1' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'pole2' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'pole3' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'pole4' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'pole5' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'hod1' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'hod2' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'hod3' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'hod4' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'hod5' => $this->smallInteger(1)->defaultValue(0)->unsigned(),
            'time_start_at' => $this->integer()->defaultValue(0)->unsigned(),
            'time_over_at' => $this->integer()->defaultValue(0)->unsigned(),
            'created_at' => $this->integer()->unsigned(),
        ], $this->tableOptions);

        $this->createIndex('{{%idx-game_saper-kon}}','{{%game_saper}}','kon');
        $this->createIndex('{{%idx-game_saper-user_gamer}}','{{%game_saper}}','user_gamer');

        $this->addForeignKey('fki-game_saper-user_id-user-id',
            '{{%game_saper}}',
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
        $this->dropForeignKey('fki-game_saper-user_id-user-id', '{{%game_saper}}');

        $this->dropIndex('{{%idx-game_saper-kon}}','{{%game_saper}}');
        $this->dropIndex('{{%idx-game_saper-user_gamer}}','{{%game_saper}}');

        $this->dropTable('{{%game_saper}}');
    }
}
