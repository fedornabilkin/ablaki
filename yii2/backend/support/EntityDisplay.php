<?php
namespace backend\support;

use common\modules\world\admin\WorldEntityCatalog;
use mdm\admin\components\Helper;
use Yii;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\Html;

/** Shared, read-only presentation of entity names and foreign keys. */
final class EntityDisplay
{
    public static function routes(): array
    {
        $routes = ['world_node' => '/world/node', 'world_template' => '/world/template', 'world_event' => '/world/event',
            'user' => '/user/admin', 'persone' => '/person', 'craft_item' => '/world/craft/item',
            'craft_recipe' => '/world/craft/recipe', 'craft_category' => '/world/craft/category',
            'craft_history' => '/world/craft/history', 'craft_inventory' => '/world/craft/inventory',
            'todo' => '/todo', 'fact' => '/fact', 'comission' => '/commission',
            'forum_theme' => '/forum/theme', 'forum_comment' => '/forum/comment'];
        foreach (WorldEntityCatalog::definitions() as $table => $definition) if (!isset($routes[$table])) $routes[$table] = '/' . $definition['route'];
        return $routes;
    }
    public static function table($model): ?string
    {
        return $model instanceof ActiveRecord ? trim($model::tableName(), '{}%') : null;
    }
    public static function target(?string $table, string $field): ?string
    {
        if ($table) {
            $schema = Yii::$app->db->schema->getTableSchema($table);
            foreach ($schema ? $schema->foreignKeys : [] as $foreign) {
                if (count($foreign) === 2 && isset($foreign[$field]) && isset(self::routes()[$foreign[0]])) return $foreign[0];
            }
        }
        if (preg_match('/(^|_)user_id$/D', $field) || in_array($field, ['author_id', 'buyer_id', 'seller_id'], true)) return 'user';
        if (in_array($field, ['item_id', 'seed_item_id', 'yield_item_id', 'water_item_id', 'tool_id'], true)) return 'craft_item';
        if ($field === 'category_id' && strpos((string)$table, 'craft_') === 0) return 'craft_category';
        if ($field === 'recipe_id') return 'craft_recipe';
        if ($field === 'theme_id') return 'forum_theme';
        if ($field === 'comment_id' && strpos((string)$table, 'forum_') === 0) return 'forum_comment';
        if ($field === 'template_id' || $field === 'parent_template_id') return 'world_template';
        if ($field === 'storage_id') return 'craft_storage';
        if (preg_match('/(^|_)node_id$/D', $field) || in_array($field, ['settlement_id', 'building_id', 'room_id', 'bed_id', 'garden_id', 'starter_site_id', 'garden_node_id'], true)
            || ($field === 'parent_id' && (!$table || $table === 'world_node'))) return 'world_node';
        return null;
    }
    public static function url(string $table, array $row): ?array
    {
        $route = self::routes()[$table] ?? null;
        if (!$route) return null;
        if ($table === 'world_slot') return \common\modules\world\models\admin\WorldRelations::route($table, $row);
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema || !$schema->primaryKey) return null;
        $key = array_intersect_key($row, array_flip($schema->primaryKey));
        if (count($key) !== count($schema->primaryKey)) return null;
        return [$route . ($table === 'user' ? '/info' : '/view')] + (count($key) === 1 ? ['id' => reset($key)] : ['key' => $key]);
    }
    public static function label(string $table, array $row, int $depth = 0): string
    {
        foreach (['username', 'name', 'label', 'title', 'code'] as $field) if (!empty($row[$field])) return (string)$row[$field];
        $parts = [WorldEntityCatalog::definitions()[$table]['label'] ?? ucfirst(str_replace('_', ' ', $table))];
        $kinds = ['budget' => 'Бюджет', 'treasury' => 'Казна', 'backpack' => 'Рюкзак', 'placement' => 'Размещение', 'stockpile' => 'Склад'];
        if (!empty($row['role']) || !empty($row['kind'])) { $kind = $row['role'] ?? $row['kind']; $parts[] = $kinds[$kind] ?? $kind; }
        if ($depth < 2) foreach (['node_id', 'storage_id', 'item_id', 'owner_user_id'] as $field) {
            $target = self::target($table, $field);
            if ($target && !empty($row[$field]) && ($related = self::row($target, $row[$field]))) { $parts[] = self::label($target, $related, $depth + 1); break; }
        }
        return implode(' · ', array_unique($parts));
    }
    public static function row(string $table, $id): ?array
    {
        if (!isset(self::routes()[$table]) || (!is_scalar($id) && $id !== null)) return null;
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema || count($schema->primaryKey) !== 1) return null;
        $cache = Yii::$app->params['entityDisplayRows'] ?? [];
        if (array_key_exists((string)$id, $cache[$table] ?? [])) return $cache[$table][(string)$id];
        $row = (new Query())->select(self::fields($table))->from($table)->where([$schema->primaryKey[0] => $id])->one();
        Yii::$app->params['entityDisplayRows'][$table][(string)$id] = $row ?: null;
        return $row ?: null;
    }
    public static function fields(string $table): array
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
        return array_values(array_unique(array_merge($schema->primaryKey, array_intersect(array_keys($schema->columns),
            ['username', 'name', 'label', 'title', 'code', 'kind', 'role', 'node_id', 'node_type', 'hierarchy_level', 'storage_id', 'item_id', 'owner_user_id', 'position']))));
    }
    public static function warm(array $models): void
    {
        $ids = [];
        foreach ($models as $model) {
            $table = self::table($model);
            if (!$table) continue;
            foreach ($model->getAttributes() as $field => $id) if ($id !== null && substr($field, -3) === '_id' && ($target = self::target($table, $field))) $ids[$target][(string)$id] = $id;
        }
        foreach ($ids as $table => $values) {
            $schema = Yii::$app->db->schema->getTableSchema($table);
            if (!$schema || count($schema->primaryKey) !== 1) continue;
            $pk = $schema->primaryKey[0];
            foreach ((new Query())->select(self::fields($table))->from($table)->where([$pk => array_values($values)])->all() as $row) Yii::$app->params['entityDisplayRows'][$table][(string)$row[$pk]] = $row;
        }
    }
    public static function link(string $table, array $row): string
    {
        $url = self::url($table, $row); $label = self::label($table, $row);
        return $url && Helper::checkRoute($url[0]) ? Html::a(Html::encode($label), $url) : Html::encode($label);
    }
    public static function value(string $table, string $field, $value, array $row): ?string
    {
        if (self::creditField($field) && $value !== null && $value !== '' && is_numeric($value)) return Html::encode(self::credits($value));
        if (in_array($field, ['build_seconds','required_seconds','worked_seconds'], true) && is_numeric($value))
            return Html::encode(\common\modules\world\models\WorldTime::duration((int)$value * \common\modules\world\models\WorldTime::rate()) . ' мира');
        if (substr($field, -4) === 'Name') {
            $foreignField = \yii\helpers\Inflector::underscore(substr($field, 0, -4)) . '_id';
            if (array_key_exists($foreignField, $row)) { $field = $foreignField; $value = $row[$field]; }
        }
        if (in_array($field, ['name', 'label', 'title', 'username'], true)) {
            $url = self::url($table, $row);
            return $url && Helper::checkRoute($url[0]) ? Html::a(Html::encode((string)$value), $url) : Html::encode((string)$value);
        }
        $target = self::target($table, $field);
        if (!$target) return null;
        if ($value === null || $value === '') return '—';
        $related = self::row($target, $value);
        return $related ? self::link($target, $related) : Html::encode('Удалённая запись');
    }
    public static function creditField(string $field): bool
    {
        return (bool)preg_match('/(^|_)(amount|price|budget|reserved|balance|credit|credits|cost|earned|charge|refund|available|lost)(_|$)/D', $field)
            && !preg_match('/(_bps|_percent|_rate|_count|_id|_json)$/D', $field);
    }
    public static function credits($value): string
    {
        try { return \common\modules\world\modules\economy\value\Money::parse((string)$value)->display(); }
        catch (\InvalidArgumentException $error) { return (string)$value; }
        catch (\OverflowException $error) { return (string)$value; }
    }
}
