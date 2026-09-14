<?php

namespace console\migrations;

/** Re-apply UTF-8 conversion for installations that skipped the first pass. */
class m260911_130000_normalize_forum_utf8 extends AbstractMigration
{
    public function up()
    {
        if ($this->db->driverName !== 'mysql') {
            return true;
        }

        foreach (['forum_theme', 'forum_comment'] as $table) {
            $this->db->createCommand(
                'ALTER TABLE ' . $this->db->quoteTableName($table)
                . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            )->execute();
            $this->db->createCommand(
                'ALTER TABLE ' . $this->db->quoteTableName($table)
                . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            )->execute();
        }
        return true;
    }

    public function down()
    {
        return true;
    }
}
