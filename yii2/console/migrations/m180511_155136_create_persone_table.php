<?php

use console\migrations\AbstractMigration;

/**
 * Handles the creation of table `persone`.
 */
class m180511_155136_create_persone_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%persone}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->bigInteger(),
            'balance' => $this->double()->defaultValue(0)->unsigned(),
            'balance_in' => $this->double()->defaultValue(0)->unsigned(),
            'balance_out' => $this->double()->defaultValue(0)->unsigned(),
            'credit' => $this->double()->defaultValue(0)->unsigned(),
            'refovod' => $this->bigInteger()->defaultValue(0)->unsigned(),
            'rating' => $this->double()->defaultValue(0)->unsigned(),
            'referrer' => $this->text(),
            'bonus_count' => $this->bigInteger()->defaultValue(0)->unsigned(),
            'autoriz' => $this->bigInteger()->defaultValue(0)->unsigned(),
        ], $this->tableOptions);

        $this->addForeignKey('fki-persone-user_id-user-id',
            '{{%persone}}',
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
        $this->dropForeignKey('fki-persone-user_id-user-id', '{{%persone}}');

        $this->dropTable('{{%persone}}');
    }
}
