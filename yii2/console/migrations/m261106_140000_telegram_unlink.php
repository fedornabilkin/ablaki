<?php

use yii\db\Migration;

/** Preserve identity generations so unlinking also revokes earlier approvals. */
final class m261106_140000_telegram_unlink extends Migration
{
    public function up()
    {
        foreach ([
            ['{{%auth_identity}}', 'revision', $this->integer()->notNull()->defaultValue(0)],
            ['{{%telegram_auth_request}}', 'identity_revision', $this->integer()->null()],
        ] as [$table, $column, $type]) {
            if (!isset($this->db->schema->getTableSchema($table, true)->columns[$column])) {
                $this->addColumn($table, $column, $type);
            }
        }
    }

    public function down()
    {
        echo "Keep identity revisions: removing them would restore revoked approvals.\n";
        return false;
    }
}
