<?php

use console\migrations\AbstractMigration;

/**
 * Class m260612_120000_add_column_description_in_persone_table
 */
class m260612_120000_add_column_description_in_persone_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('persone', 'description', $this->text()->null());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('persone', 'description');
    }
}
