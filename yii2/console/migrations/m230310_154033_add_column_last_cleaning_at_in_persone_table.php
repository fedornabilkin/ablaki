<?php

use console\migrations\AbstractMigration;

/**
 * Class m230310_154033_add_column_last_cleaning_at_in_persone_table
 */
class m230310_154033_add_column_last_cleaning_at_in_persone_table extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('persone', 'last_cleaning_at', $this->integer()->defaultValue(0));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('persone', 'last_cleaning_at');
    }
}
