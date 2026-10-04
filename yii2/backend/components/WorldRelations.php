<?php
namespace backend\components;

use common\modules\world\model\WorldNodeForm;
use mdm\admin\components\Helper;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\helpers\Html;
use yii\web\HttpException;

/** Read-only relation navigation; all writes remain in the corresponding domain editor. */
class WorldRelations
{
    public static function schema(string $table): \yii\db\TableSchema
    {
        if (!isset(WorldEntityCatalog::definitions()[$table]) && !in_array($table, array_merge(['world_node'], array_map(static function ($type) { return 'world_' . strtolower($type); }, array_keys(WorldNodeForm::TYPES))), true)) {
            throw new HttpException(404, 'Раздел не найден.');
        }
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema) throw new HttpException(503, 'Раздел станет доступен после обновления схемы мира.');
        return $schema;
    }

    public static function key(string $table, $id, $key = []): array
    {
        $columns = self::schema($table)->primaryKey;
        if (count($columns) === 1 && $id !== null) $key = [$columns[0] => $id];
        if (!is_array($key) || array_diff($columns, array_keys($key)) || array_diff(array_keys($key), $columns) || !$columns) throw new HttpException(422, 'Укажите ключ записи.');
        foreach ($key as $field => $value) self::validate($table, $field, $value);
        return $key;
    }

    public static function validate(string $table, string $field, $value): void
    {
        $column = self::schema($table)->columns[$field] ?? null;
        if (!$column || (!is_string($value) && !is_int($value)) || strlen((string)$value) > 120
            || (in_array($column->type, ['integer', 'bigint', 'smallint'], true) && !preg_match('/^-?[0-9]{1,19}$/D', (string)$value))) throw new HttpException(422, 'Некорректный фильтр: ' . WorldEntityCatalog::label($field));
    }

    public static function route(string $table, array $row, string $action = 'view'): ?array
    {
        if ($table === 'world_node') return ['/' . WorldNodeForm::ROUTES[$row['node_type']] . '/' . $action, 'id' => $row['id']];
        foreach (WorldNodeForm::ROUTES as $type => $route) if ($table === 'world_' . strtolower($type)) return ['/' . $route . '/' . $action, 'id' => $row['node_id']];
        $definition = WorldEntityCatalog::definitions()[$table] ?? null;
        if (!$definition) return null;
        $url = ['/' . $definition['route'] . '/' . $action];
        if ($table === 'world_slot') {
            $node = (new Query())->select('node_id')->from('craft_storage')->where(['id' => $row['storage_id']])->scalar(Yii::$app->db);
            return $node ? $url + ['node_id' => $node, 'position' => $row['position']] : null;
        }
        $pk = self::schema($table)->primaryKey;
        if (count($pk) === 1) return $url + ['id' => $row[$pk[0]]];
        return $url + ['key' => array_intersect_key($row, array_flip($pk))];
    }

    public static function link(?array $url, string $label, array $options = []): string
    {
        return $url && Helper::checkRoute($url[0]) ? Html::a(Html::encode($label), $url, $options) : Html::encode($label);
    }

    public static function value(string $table, string $field, $value, array $record = []): string
    {
        if ($value === null) return '—';
        if (in_array($field, ['item_id', 'seed_item_id', 'yield_item_id', 'water_item_id'], true)) {
            $name = (new Query())->select('name')->from('craft_item')->where(['id' => $value])->scalar(Yii::$app->db);
            if ($name !== false) return self::link(['/craft/item/view', 'id' => $value], '#' . $value . ' · ' . $name);
        }
        foreach (self::schema($table)->foreignKeys as $foreign) {
            $parent = $foreign[0]; unset($foreign[0]);
            if (!isset($foreign[$field])) continue;
            if ($parent !== 'world_node' && !isset(WorldEntityCatalog::definitions()[$parent]) && !in_array($parent, ['world_bed', 'world_room', 'world_building', 'world_plot', 'world_settlement', 'world_region'], true)) continue;
            // Do not query or disclose parent names unless its card is allowed.
            $parentRoute = $parent === 'world_node' ? null : (WorldEntityCatalog::definitions()[$parent]['route'] ?? ('world-' . substr($parent, 6)));
            if ($parentRoute !== null && !Helper::checkRoute('/' . $parentRoute . '/view')) continue;
            $condition = [];
            foreach ($foreign as $source => $target) {
                $part = $source === $field ? $value : ($record[$source] ?? null);
                if ($part === null) continue 2;
                $condition[$target] = $part;
            }
            $row = (new Query())->from($parent)->where($condition)->one(Yii::$app->db);
            if ($row) {
                $url = self::route($parent, $row);
                if ($url && Helper::checkRoute($url[0])) return self::link($url, '#' . $value . (isset($row['name']) ? ' · ' . $row['name'] : ''));
            }
        }
        if (preg_match('/(_at|_until)$/D', $field) && is_numeric($value) && (int)$value > 0) return Html::encode(Yii::$app->formatter->asDatetime((int)$value));
        if (substr($field, -5) === '_json') {
            $decoded = json_decode($value, true);
            return Html::tag('pre', Html::encode($decoded === null ? $value : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), ['style' => 'max-width:100%;white-space:pre-wrap;max-height:24em;overflow:auto']);
        }
        return Html::encode((string)$value);
    }

    public static function editable(string $table, array $row): bool
    {
        if ($table === 'world_node') return $row['status'] === 'active';
        if ($table === 'world_premises_offer') return $row['status'] === 'published';
        if ($table === 'world_garden_offer') return $row['active_settlement_id'] !== null;
        if ($table === 'world_slot') {
            $type = (new Query())->select('n.node_type')->from(['n' => 'world_node'])->innerJoin(['s' => 'craft_storage'], '[[s.node_id]]=[[n.id]]')->where(['s.id' => $row['storage_id']])->scalar(Yii::$app->db);
            return $type === 'ROOM';
        }
        return in_array($table, ['world_crop', 'world_warehouse_policy'], true);
    }

    public static function actions(string $table, array $row): string
    {
        $links = [];
        foreach (['view' => 'Просмотр', 'update' => 'Изменить'] as $action => $label) {
            if ($action === 'update' && !self::editable($table, $row)) continue;
            $url = self::route($table, $row, $action);
            if ($url && Helper::checkRoute($url[0])) $links[] = Html::a($label, $url, ['class' => 'btn btn-default btn-xs']);
        }
        if (self::editable($table, $row) && $table !== 'world_warehouse_policy') {
            $url = self::route($table, $row, 'delete');
            if ($url && Helper::checkRoute($url[0])) {
                if ($table === 'world_slot') {
                    $view = self::route($table, $row); $view['#'] = 'delete';
                    if (Helper::checkRoute($view[0])) $links[] = Html::a('Удалить…', $view, ['class' => 'btn btn-danger btn-xs']);
                } else {
                    $form = Html::beginForm($url, 'post');
                    if ($table === 'world_node') $form .= Html::hiddenInput('revision', $row['revision']);
                    if ($table !== 'world_crop') $form .= Html::textInput('reason', '', ['required' => true, 'maxlength' => 255, 'class' => 'form-control input-sm', 'placeholder' => 'Причина удаления', 'aria-label' => 'Причина удаления']);
                    $form .= Html::submitButton($table === 'world_node' ? 'В архив…' : 'Снять с публикации', ['class' => 'btn btn-danger btn-xs', 'data-confirm' => 'Удалить из действующих записей? История сохранится.']) . Html::endForm();
                    $links[] = '<details><summary>Удалить…</summary>' . $form . '</details>';
                }
            }
        }
        return implode(' ', $links) ?: '—';
    }

    public static function provider(string $table, array $where = [], string $prefix = 'records'): ActiveDataProvider
    {
        $pk = self::schema($table)->primaryKey;
        return new ActiveDataProvider(['query' => (new Query())->from($table)->where($where), 'key' => static function ($row) use ($pk) { return implode(':', array_intersect_key($row, array_flip($pk))); },
            'pagination' => ['pageSize' => 20, 'pageSizeLimit' => [20, 20], 'pageParam' => $prefix . '-page', 'pageSizeParam' => $prefix . '-size'],
            'sort' => ['sortParam' => $prefix . '-sort', 'attributes' => array_values(array_unique(array_merge($pk, array_intersect(['name', 'status', 'created_at'], array_keys(self::schema($table)->columns))))), 'defaultOrder' => array_fill_keys($pk, SORT_DESC)]]);
    }

    public static function columns(string $table): array
    {
        $schema = self::schema($table);
        $keys = array_keys($schema->columns);
        $fields = array_unique(array_merge($schema->primaryKey, array_intersect(['name', 'code', 'status', 'kind', 'node_id', 'settlement_id', 'bed_id', 'storage_id', 'item_id', 'item_quantity', 'quantity', 'price', 'version', 'created_at'], $keys), $keys));
        $fields = array_slice(array_values(array_filter($fields, static function ($field) { return substr($field, -5) !== '_json'; })), 0, 8);
        $columns = [];
        foreach ($fields as $field) $columns[] = ['attribute' => $field, 'label' => WorldEntityCatalog::label($field), 'format' => 'raw', 'value' => static function ($row) use ($table, $field) { return self::value($table, $field, $row[$field], $row); }];
        $columns[] = ['label' => 'Действия', 'format' => 'raw', 'value' => static function ($row) use ($table) { return self::actions($table, $row); }];
        return $columns;
    }

    public static function related(string $table, array $row): array
    {
        $targets = [$table => $row];
        if ($table === 'world_node' && $row['node_type'] !== 'WORLD') $targets['world_' . strtolower($row['node_type'])] = ['node_id' => $row['id']];
        $panels = [];
        foreach (WorldEntityCatalog::definitions() as $child => $definition) {
            if (!Helper::checkRoute('/' . $definition['route'] . '/index')) continue;
            $schema = Yii::$app->db->schema->getTableSchema($child);
            if (!$schema) continue;
            $conditions = [];
            foreach ($schema->foreignKeys as $foreign) {
                $parent = $foreign[0]; unset($foreign[0]);
                if (!isset($targets[$parent])) continue;
                $condition = [];
                foreach ($foreign as $field => $target) {
                    if (!isset($targets[$parent][$target])) continue 2;
                    $condition[$field] = $targets[$parent][$target];
                }
                if ($condition && !in_array($condition, $conditions, true)) $conditions[] = $condition;
            }
            // Slots are attached to the object's placement storage, not directly to the node.
            if ($table === 'world_node' && $child === 'world_slot') $conditions[] = ['storage_id' => (new Query())->select('id')->from('craft_storage')->where(['node_id' => $row['id']])];
            if ($table === 'world_slot' && $child === 'craft_inventory') $conditions[] = ['storage_id' => $row['storage_id'], 'slot' => $row['position']];
            if ($table === 'world_crop' && $child === 'world_crop_cycle') $conditions[] = ['crop_revision_id' => (new Query())->select('id')->from('world_crop_revision')->where(['crop_id' => $row['id']])];
            if (!$conditions) continue;
            $provider = self::provider($child, count($conditions) === 1 ? $conditions[0] : array_merge(['or'], $conditions), 'rel-' . str_replace('_', '-', $child));
            $create = null;
            if ($table === 'world_node' && $row['status'] === 'active') {
                if ($row['node_type'] === 'SETTLEMENT' && in_array($child, ['world_premises_offer', 'world_garden_offer'], true)) $create = ['/' . $definition['route'] . '/create', 'settlement_id' => $row['id']];
                if ($row['node_type'] === 'ROOM' && $child === 'world_slot') $create = ['/world-slot/create', 'node_id' => $row['id']];
            }
            if ($create && !Helper::checkRoute($create[0])) $create = null;
            if ($provider->totalCount || $create) $panels[] = compact('child', 'definition', 'provider', 'create');
        }
        return $panels;
    }
}
