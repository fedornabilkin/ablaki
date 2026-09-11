<?php

namespace console\migrations;

/** Convert legacy forum tables so Cyrillic content is stored as UTF-8. */
class m260911_120000_convert_forum_text_to_utf8 extends AbstractMigration
{
    public function up()
    {
        if ($this->db->driverName !== 'mysql') {
            return true;
        }

        foreach (['forum_theme', 'forum_comment'] as $table) {
            $this->db->createCommand(
                'ALTER TABLE ' . $this->db->quoteTableName($table)
                . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            )->execute();
        }
        return true;
    }

    public function down()
    {
        if ($this->db->driverName !== 'mysql') {
            return true;
        }

        foreach (['forum_theme', 'forum_comment'] as $table) {
            $this->db->createCommand(
                'ALTER TABLE ' . $this->db->quoteTableName($table)
                . ' CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci'
            )->execute();
        }
        return true;
    }
}
