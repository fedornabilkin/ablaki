<?php

use yii\db\Migration;

/** Additive, restartable DDL; no credentials or existing account data are rewritten. */
final class m261104_120000_telegram_auth extends Migration
{
    public function up()
    {
        $options = $this->db->driverName === 'mysql' ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : null;
        $ownerId = $this->db->schema->getTableSchema('{{%user}}', true)->columns['id'];
        // Legacy production uses INT UNSIGNED; foreign keys must match its exact type.
        $ownerReference = $this->db->driverName === 'mysql' ? $ownerId->dbType . ' NULL' : $this->integer()->null();
        $tables = [
            '{{%auth_identity}}' => [
                'id' => $this->primaryKey(),
                'provider' => $this->string(20)->notNull(),
                'subject' => $this->string(32)->notNull(),
                'user_id' => $ownerReference,
                'username' => $this->string(64)->null(),
                'created_at' => $this->integer()->notNull(),
            ],
            '{{%telegram_auth_request}}' => [
                'id' => $this->string(43)->notNull()->append('PRIMARY KEY'),
                'proof_hash' => $this->string(64)->notNull(),
                'status' => $this->string(16)->notNull(),
                'purpose' => $this->string(8)->notNull(),
                'allow_signup' => $this->boolean()->notNull()->defaultValue(false),
                'target_user_id' => $ownerReference,
                'target_key_hash' => $this->string(64)->null(),
                'subject' => $this->string(32)->null(),
                'username' => $this->string(64)->null(),
                'display_code' => $this->string(6)->notNull(),
                'created_at' => $this->integer()->notNull(),
                'expires_at' => $this->integer()->notNull(),
                'completed_at' => $this->integer()->null(),
                'result_user_id' => $ownerReference,
                'result_key_hash' => $this->string(64)->null(),
            ],
            '{{%auth_rate_limit}}' => [
                'id' => $this->string(64)->notNull()->append('PRIMARY KEY'),
                'hits' => $this->integer()->notNull()->defaultValue(0),
                'expires_at' => $this->integer()->notNull(),
            ],
            '{{%auth_audit}}' => [
                'id' => $this->primaryKey(),
                'event' => $this->string(32)->notNull(),
                'user_id' => $ownerReference,
                'created_at' => $this->integer()->notNull(),
            ],
        ];
        foreach ($tables as $name => $columns) {
            if (!$this->db->schema->getTableSchema($name, true)) $this->createTable($name, $columns, $options);
        }
        // MySQL DDL commits immediately: repair tables left by an interrupted first install.
        if ($this->db->driverName === 'mysql') {
            foreach ([['{{%auth_identity}}', 'user_id'], ['{{%telegram_auth_request}}', 'target_user_id'],
                ['{{%telegram_auth_request}}', 'result_user_id'], ['{{%auth_audit}}', 'user_id']] as [$table, $column]) {
                $reference = $this->db->schema->getTableSchema($table, true)->columns[$column];
                if ($reference->dbType !== $ownerId->dbType) $this->alterColumn($table, $column, $ownerReference);
            }
        }
        $indexes = [
            ['auth_identity_subject', '{{%auth_identity}}', ['provider', 'subject'], true],
            ['auth_identity_user', '{{%auth_identity}}', ['provider', 'user_id'], true],
            ['telegram_auth_expiry', '{{%telegram_auth_request}}', ['expires_at'], false],
            ['auth_rate_expiry', '{{%auth_rate_limit}}', ['expires_at'], false],
            ['auth_audit_time', '{{%auth_audit}}', ['created_at'], false],
        ];
        foreach ($indexes as [$name, $table, $columns, $unique]) {
            $existing = $this->db->schema->getTableIndexes($table, true);
            if (!array_filter($existing, static fn($index) => $index->name === $name)) $this->createIndex($name, $table, $columns, $unique);
        }
        // Removing an account must also remove its login binding.
        $keys = $this->db->schema->getTableForeignKeys('{{%auth_identity}}', true);
        if ($this->db->driverName !== 'sqlite' && !array_filter($keys, static fn($key) => $key->name === 'auth_identity_owner')) {
            $this->addForeignKey('auth_identity_owner', '{{%auth_identity}}', 'user_id', '{{%user}}', 'id', 'CASCADE', 'RESTRICT');
        }
        // Telegram does not provide an email. Preserve unique emails, allow NULL for this login method.
        $email = $this->db->schema->getTableSchema('{{%user}}', true)->columns['email'];
        if (!$email->allowNull) $this->alterColumn('{{%user}}', 'email', $this->string(255)->null());
    }

    public function down()
    {
        echo "Disable Telegram in the administration settings to roll back the feature without losing account bindings.\n";
        return false;
    }
}
