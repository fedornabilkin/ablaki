<?php
use common\modules\world\support\WorldDomainMigration;

/** System-published profession defaults have no human author; retain all existing attribution. */
class m260928_003400_system_profession_author extends WorldDomainMigration
{
    public function safeUp()
    {
        $column = $this->db->schema->getTableSchema('profession_revision', true)->getColumn('author_user_id');
        if (!$column->allowNull) $this->alterColumn('profession_revision', 'author_user_id', $column->dbType . ' NULL');
    }
}
