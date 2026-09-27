<?php
namespace common\services\game;

/** Additive DDL is resumable on MySQL/MariaDB; data writes use separate transactions. */
abstract class WorldMigration extends \yii\db\Migration
{
    public function up() { return $this->db->driverName === 'mysql' ? $this->safeUp() : parent::up(); }
    public function down() { echo "World data is retained. Use a forward migration.\n"; return false; }
    protected function reference(string $table, string $column = 'id'): string
    {
        $schema = $this->db->schema->getTableSchema($table, true);
        if (!$schema || !isset($schema->columns[$column])) throw new \RuntimeException('Missing prerequisite: ' . $table . '.' . $column);
        return $schema->columns[$column]->dbType;
    }
    protected function table(string $name, array $columns): void
    {
        if (!$this->db->schema->getTableSchema($name, true)) {
            $this->createTable($name, $columns, $this->db->driverName === 'mysql' ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : null);
        }
    }
    protected function index(string $name, string $table, array $columns, bool $unique = false): void
    {
        foreach ($this->db->schema->getTableIndexes($table, true) as $index) if ($index->name === $name) return;
        $this->createIndex($name, $table, $columns, $unique);
    }
    protected function foreign(string $name, string $table, string $column, string $parent, string $target = 'id'): void
    {
        if ($this->db->driverName === 'sqlite') return; // Production constraints target MySQL and PostgreSQL.
        foreach ($this->db->schema->getTableForeignKeys($table, true) as $key) if ($key->name === $name) return;
        $this->addForeignKey($name, $table, $column, $parent, $target, 'RESTRICT', 'RESTRICT');
    }
}
