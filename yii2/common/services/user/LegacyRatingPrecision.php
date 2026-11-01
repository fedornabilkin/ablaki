<?php
namespace common\services\user;

use yii\db\Connection;

/** Widen legacy single-precision ratings without recalculating existing awards. */
final class LegacyRatingPrecision
{
    public const COLUMNS = ['persone' => ['rating'], 'history_rating' => ['rating', 'rating_up']];

    public function __construct(private readonly Connection $db) {}

    public function upgrade(): void
    {
        if ($this->db->driverName === 'sqlite') return; // SQLite REAL already uses binary64.
        if (!in_array($this->db->driverName, ['mysql', 'pgsql'], true)) throw new \RuntimeException('Unsupported rating database.');
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $name) {
                $column = $this->db->schema->getTableSchema($table, true)?->getColumn($name);
                if (!$column) throw new \RuntimeException('Missing rating column: ' . $table . '.' . $name);
                if (!preg_match('/^(float(?:\(\d+(?:,\d+)?\))?(?: unsigned)?|real|float4)$/i', $column->dbType)) continue;
                $type = $this->db->schema->createColumnSchemaBuilder('double');
                $column->allowNull ? $type->null() : $type->notNull();
                if ($column->defaultValue !== null) $type->defaultValue($column->defaultValue);
                elseif ($column->allowNull) $type->defaultValue(null);
                if ($column->unsigned) $type->unsigned();
                if (is_string($column->comment) && $column->comment !== '') $type->comment($column->comment);
                $this->db->createCommand()->alterColumn($table, $name, $type)->execute();
            }
        }
        $this->db->schema->refresh();
    }

    /** Compare binary64 numeric values, not MySQL's abbreviated FLOAT display. */
    public function snapshot(): array
    {
        $result = [];
        foreach (self::COLUMNS as $table => $numeric) {
            $schema = $this->db->schema->getTableSchema($table, true);
            if (!$schema) throw new \RuntimeException('Missing rating table.');
            $select = [];
            foreach ($schema->columns as $name => $column) {
                $quoted = $this->db->quoteColumnName($name);
                $double = $this->db->driverName === 'pgsql' ? 'CAST(' . $quoted . ' AS DOUBLE PRECISION)' : $quoted . ' + 0e0';
                $select[] = in_array($name, $numeric, true) ? $double . ' AS ' . $quoted : $quoted;
            }
            $hash = hash_init('sha256'); $rows = 0;
            $reader = $this->db->createCommand('SELECT ' . implode(',', $select) . ' FROM ' . $this->db->quoteTableName($table) . ' ORDER BY ' . $this->db->quoteColumnName('id'))->query();
            foreach ($reader as $row) {
                foreach ($row as $value) hash_update($hash, $value === null ? 'N;' : strlen((string)$value) . ':' . (string)$value . ';');
                $rows++;
            }
            $reader->close();
            $result[$table] = ['rows' => $rows, 'sha256' => hash_final($hash)];
        }
        return $result;
    }
}
