<?php

use yii\db\Migration;

class m260905_120000_create_forum_comment_gift_table extends Migration
{
    public function up()
    {
        // MySQL commits DDL implicitly. A failed FK must be resumable without
        // discarding the table or any gifts written before the retry.
        return $this->db->driverName === 'mysql' ? $this->safeUp() : parent::up();
    }

    public function safeUp()
    {
        $this->requireTransactionalTables();
        $userId = $this->db->getTableSchema('{{%user}}', true)->getColumn('id')->dbType . ' NOT NULL';
        $commentId = $this->db->getTableSchema('{{%forum_comment}}', true)->getColumn('id')->dbType . ' NOT NULL';
        $options = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB' : null;
        if (!$this->db->getTableSchema('{{%forum_comment_gift}}', true)) $this->createTable('{{%forum_comment_gift}}', [
            'id' => $this->primaryKey(),
            'comment_id' => $commentId,
            'user_id' => $userId,
            'recipient_id' => $userId,
            'created_at' => $this->integer()->notNull(),
        ], $options);
        $indexes = $this->db->schema->getTableIndexes('{{%forum_comment_gift}}', true);
        foreach ([
            ['uq-forum-gift-comment-user', ['comment_id', 'user_id'], true],
            ['idx-forum-gift-user', ['user_id'], false],
            ['idx-forum-gift-recipient', ['recipient_id'], false],
        ] as [$name, $columns, $unique]) {
            $found = false;
            foreach ($indexes as $index) {
                if ($index->name !== $name) continue;
                if ($index->columnNames !== $columns || $index->isUnique !== $unique) {
                    throw new \RuntimeException('Unexpected gift index definition: ' . $name);
                }
                $found = true;
            }
            if (!$found) $this->createIndex($name, '{{%forum_comment_gift}}', $columns, $unique);
        }
        $keys = $this->db->schema->getTableForeignKeys('{{%forum_comment_gift}}', true);
        foreach (['comment' => ['comment_id', 'forum_comment'], 'user' => ['user_id', 'user'], 'recipient' => ['recipient_id', 'user']] as $suffix => [$column, $parent]) {
            $name = 'fk-forum-gift-' . $suffix;
            $found = false;
            foreach ($keys as $key) if ($key->name === $name) $found = true;
            if (!$found) $this->addForeignKey($name, '{{%forum_comment_gift}}', $column, '{{%' . $parent . '}}', 'id', 'RESTRICT', 'RESTRICT');
        }
    }

    private function requireTransactionalTables(): void
    {
        if ($this->db->driverName !== 'mysql') return;
        if ($this->db->getTransaction()) throw new \RuntimeException('Run gift DDL outside a transaction.');
        foreach (['user', 'forum_comment', 'forum_comment_gift'] as $table) {
            $name = $this->db->tablePrefix . $table;
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $name])->queryScalar();
            if ($engine === false && $table === 'forum_comment_gift') continue;
            if (strtolower((string)$engine) !== 'innodb') {
                throw new \RuntimeException('Gift migration requires InnoDB: ' . $name . '. Convert and verify legacy tables in the maintenance window before retrying.');
            }
        }
    }

    public function safeDown()
    {
        // Financial audit records must not be removed by an automatic rollback.
        if ((new \yii\db\Query())->from('{{%forum_comment_gift}}')->exists($this->db)) return false;
        $this->dropTable('{{%forum_comment_gift}}');
    }
}
