<?php
namespace common\modules\world\support;

/** Additive domain schemas with real referenced types and restartable indexes/FKs. */
abstract class WorldDomainMigration extends WorldMigration
{
    protected function domain(string $table, array $columns, array $links = [], array $unique = [], array $indexes = [], array $primary = []): void
    {
        if (!$primary) $columns = ['id' => $this->primaryKey()] + $columns;
        foreach ($links as $column => $link) $columns[$column] = $this->reference($link[0], $link[1] ?? 'id') . (($link[2] ?? true) ? ' NOT NULL' : '');
        if ($primary) $columns[] = 'PRIMARY KEY ([[' . implode(']], [[', $primary) . ']])';
        $this->table($table, $columns);
        foreach ($unique as $fields) $this->index($this->keyName('ux', $table, $fields), $table, $fields, true);
        foreach ($indexes as $fields) $this->index($this->keyName('ix', $table, $fields), $table, $fields);
        foreach ($links as $column => $link) $this->foreign($this->keyName('fk', $table, [$column]), $table, $column, $link[0], $link[1] ?? 'id');
    }
    private function keyName(string $kind, string $table, array $fields): string
    {
        $full = $kind . '_' . $table . '_' . implode('_', $fields);
        return strlen($full) <= 60 ? $full : substr($full, 0, 45) . '_' . substr(sha1($full), 0, 12);
    }
}
