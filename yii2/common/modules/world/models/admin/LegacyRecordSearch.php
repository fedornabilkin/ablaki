<?php
namespace common\modules\world\models\admin;

use yii\db\Query;
use yii\web\HttpException;

final class LegacyRecordSearch
{
    public static function one(string $table, $id, array $key): array
    {
        $row = (new Query())->from($table)->where(WorldRelations::key($table, $id, $key))->one();
        if (!$row) throw new HttpException(404, 'Запись не найдена.'); return $row;
    }
    public static function search(string $table, $q, $filters): array
    {
        $schema = WorldRelations::schema($table);
        if (!is_string($q) || mb_strlen($q, 'UTF-8') > 120 || !is_array($filters)) throw new HttpException(422, 'Некорректный поиск.');
        $fields = array_values(array_filter(array_keys($schema->columns), static function ($field) use ($schema) {
            return in_array($field, $schema->primaryKey, true) || substr($field, -3) === '_id' || in_array($field, ['status', 'kind', 'state'], true);
        }));
        $where = [];
        foreach ($filters as $field => $value) {
            if (!in_array($field, $fields, true)) throw new HttpException(422, 'Неизвестный фильтр.');
            if ($value === '') continue; WorldRelations::validate($table, $field, $value); $where[$field] = $value;
        }
        $provider = WorldRelations::provider($table, $where);
        if (trim($q) !== '') {
            $search = ['or'];
            foreach (array_intersect(['name', 'code', 'reason', 'status', 'kind'], array_keys($schema->columns)) as $field) $search[] = ['like', $field, trim($q)];
            if (ctype_digit($q) && isset($schema->columns['id'])) $search[] = ['id' => $q];
            $provider->query->andWhere(count($search) > 1 ? $search : '0=1');
        }
        return compact('provider', 'q', 'filters', 'fields');
    }
}
