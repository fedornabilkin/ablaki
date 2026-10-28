<?php
namespace common\modules\world\support;

use common\modules\world\models\domain\WorldFlags;
use yii\db\Connection;
use yii\db\Query;

/** Metadata only: neither credentials nor application row contents belong in this report. */
class WorldSchemaAudit
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function comparable(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/', '$1', $type);
        $type = strtr($type, ['int4' => 'integer', 'int8' => 'bigint', 'int2' => 'smallint']);
        return preg_replace('/^int\b/', 'integer', $type);
    }
    public function report(string $migrationPath, array $legacyTables): array
    {
        $db = $this->db; $db->open(); $tables = array_fill_keys($legacyTables, true);
        foreach ($db->schema->getTableNames() as $name) if (preg_match('/^(world_|economy_|game_|npc(?:_|$)|production_|profession(?:_|$)|actor_|requirement_|achievement$|quest_|notification_|treasury_protection_|inventory_(?:reservation|movement)$|equipment_reservation|craft_)/D', $name)) $tables[$name] = true;
        foreach (['world_registry', 'economy_subject', 'economy_account', 'economy_account_backfill_run', 'economy_account_backfill_item'] as $table) $tables[$table] = true;
        ksort($tables); $result = ['generated_at' => time(), 'read_only' => true, 'driver' => $db->driverName, 'server_version' => $db->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), 'expected_schema_version' => WorldFlags::SCHEMA_VERSION, 'tables' => [], 'issues' => []];
        foreach (array_keys($tables) as $table) {
            $schema = $db->schema->getTableSchema($table, true);
            if (!$schema) { $result['tables'][$table] = ['missing' => true]; $result['issues'][] = ['code' => 'TABLE_MISSING', 'table' => $table]; continue; }
            $columns = [];
            foreach ($schema->columns as $column) $columns[$column->name] = ['type' => $column->dbType, 'nullable' => $column->allowNull, 'primary' => $column->isPrimaryKey];
            $indexes = [];
            foreach ($db->schema->getTableIndexes($table, true) as $index) $indexes[] = ['name' => $index->name, 'columns' => $index->columnNames, 'unique' => $index->isUnique];
            $entry = ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $schema->foreignKeys];
            if ($db->driverName === 'mysql') {
                $entry['engine'] = $db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
                if (strtolower((string)$entry['engine']) !== 'innodb') $result['issues'][] = ['code' => 'NON_TRANSACTIONAL_ENGINE', 'table' => $table, 'engine' => $entry['engine']];
            }
            foreach ($schema->foreignKeys as $name => $definition) {
                $parentName = $definition[0]; $parent = $db->schema->getTableSchema($parentName);
                foreach ($definition as $column => $target) {
                    if ($column === 0) continue;
                    $sourceColumn = $schema->getColumn($column); $targetColumn = $parent ? $parent->getColumn($target) : null;
                    if (!$sourceColumn || !$targetColumn || $this->comparable($sourceColumn->dbType) !== $this->comparable($targetColumn->dbType)) $result['issues'][] = ['code' => 'FK_TYPE_REVIEW', 'table' => $table, 'constraint' => $name, 'column' => $column, 'parent_table' => $parentName, 'parent_column' => $target, 'source_type' => $sourceColumn ? $sourceColumn->dbType : null, 'target_type' => $targetColumn ? $targetColumn->dbType : null];
                }
            }
            $result['tables'][$table] = $entry;
        }
        $files = glob(rtrim($migrationPath, '/\\') . '/*.php');
        if ($files === false || !$files) throw new \RuntimeException('World migration directory is unavailable.');
        $expected = array_map(static function ($file) { return basename($file, '.php'); }, $files); sort($expected, SORT_STRING);
        $applied = $db->schema->getTableSchema('{{%migration}}') ? (new Query())->select('version')->from('{{%migration}}')->where(['version' => $expected])->column($db) : [];
        $missing = array_values(array_diff($expected, $applied));
        $registry = $db->schema->getTableSchema('world_registry') ? (new Query())->from('world_registry')->where(['id' => 1])->one($db) : null;
        $result['migration_history'] = ['expected_count' => count($expected), 'applied_count' => count($applied), 'missing' => $missing];
        $result['installed_schema_version'] = $registry ? (int)$registry['schema_version'] : null;
        $result['registry_flags'] = $registry ? array_intersect_key($registry, array_flip(['world_read', 'world_write', 'storage_v2', 'economy_tick'])) : [];
        $result['installation_record_complete'] = !$missing && $registry && (int)$registry['schema_version'] === WorldFlags::SCHEMA_VERSION;
        $result['note'] = 'Observed metadata and migration history, not a gameplay activation certificate. No DDL lock: do not run concurrently with installation. FK_TYPE_REVIEW requires review, not automatic type conversion. Missing expected constraints require migration acceptance; existing FK metadata alone cannot prove their completeness.';
        return $result;
    }
}
