<?php
namespace common\modules\world\models\migration;

use yii\db\Connection;
use yii\db\Query;

/** Compatibility views keep old readers working; only world_node stores current object state. */
final class ObjectTableMerge
{
    private $db;
    private const TYPES = ['REGION', 'SETTLEMENT', 'BUILDING', 'ROOM', 'PLOT', 'BED'];
    public function __construct(Connection $db) { $this->db = $db; }
    public function run(): void
    {
        foreach (self::TYPES as $type) $this->merge($type);
        $this->db->schema->refresh();
    }
    private function merge(string $type): void
    {
        $table = 'world_' . strtolower($type); $archive = 'world_legacy_' . strtolower($type);
        $schema = $this->db->schema->getTableSchema($archive, true);
        if (!$schema) {
            $schema = $this->db->schema->getTableSchema($table, true);
            $node = $this->db->schema->getTableSchema('world_node', true);
            foreach ($schema->columns as $name => $column) {
                if ($name === 'node_id' || isset($node->columns[$name])) continue;
                $definition = $column->dbType;
                $this->db->createCommand()->addColumn('world_node', $name, $definition)->execute();
            }
            // A retry before rename simply copies the same source again. No user traffic during migration.
            $this->db->transaction(function () use ($table, $type) {
                foreach ((new Query())->from($table)->each(500, $this->db) as $row) {
                    $id = $row['node_id']; unset($row['node_id']);
                    $this->db->createCommand()->update('world_node', $row, ['id' => $id, 'node_type' => $type])->execute();
                }
            });
            $this->redirectForeignKeys($table);
            $this->db->createCommand()->renameTable($table, $archive)->execute();
        }
        // Archive tables are migration snapshots, never read or updated by gameplay.
        if ($this->db->schema->getTableSchema($table, true)) return;
        $columns = array_keys($schema->columns); $select = ['[[id]] AS [[node_id]]'];
        foreach ($columns as $name) if ($name !== 'node_id') $select[] = $this->db->quoteColumnName($name);
        $this->db->createCommand('CREATE VIEW ' . $this->db->quoteTableName($table) . ' AS SELECT '
            . implode(', ', $select) . ' FROM [[world_node]] WHERE [[node_type]]=' . $this->db->quoteValue($type))->execute();
        if ($this->db->driverName === 'sqlite') {
            $assignments = [];
            foreach ($columns as $name) if ($name !== 'node_id') $assignments[] = '[[' . $name . ']]=NEW.[[' . $name . ']]';
            $sql = 'CREATE TRIGGER ' . $table . '_update INSTEAD OF UPDATE ON [[' . $table . ']] BEGIN UPDATE [[world_node]] SET '
                . implode(', ', $assignments) . ' WHERE [[id]]=OLD.[[node_id]]; END';
            $this->db->pdo->exec($this->db->quoteSql($sql));
        }
    }
    private function redirectForeignKeys(string $table): void
    {
        if ($this->db->driverName === 'sqlite') return;
        foreach ($this->db->schema->getTableNames() as $source) {
            if (strpos($source, 'world_legacy_') === 0) continue;
            foreach ($this->db->schema->getTableForeignKeys($source, true) as $key) {
                if ($key->foreignTableName !== $table) continue;
                // One DDL statement: a MySQL interruption cannot leave a dropped, unrecorded FK.
                $drop = $this->db->driverName === 'mysql' ? ' DROP FOREIGN KEY ' : ' DROP CONSTRAINT ';
                $columns = array_map([$this->db, 'quoteColumnName'], $key->columnNames);
                $this->db->createCommand('ALTER TABLE ' . $this->db->quoteTableName($source) . $drop . $this->db->quoteColumnName($key->name)
                    . ', ADD CONSTRAINT ' . $this->db->quoteColumnName('fk-node-' . substr(hash('sha256', $source . ':' . $key->name), 0, 32)) . ' FOREIGN KEY (' . implode(', ', $columns)
                    . ') REFERENCES [[world_node]] ([[id]]) ON DELETE RESTRICT ON UPDATE RESTRICT')->execute();
            }
        }
    }
}
