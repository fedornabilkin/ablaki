<?php
namespace common\modules\world\service;

use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** The map belongs to its parent; an object's footprint uses parent coordinates. */
class WorldLayout
{
    public static function defaults(string $type, array $details = []): array
    {
        $sizes = ['WORLD' => [32, 32], 'REGION' => [20, 20], 'SETTLEMENT' => [12, 12], 'BUILDING' => [3, 3], 'ROOM' => [2, 3], 'PLOT' => [5, 5], 'BED' => [1, 1]];
        $size = ($details['plot_kind'] ?? '') === 'garden' ? [5, 2] : $sizes[$type];
        return ['map_width' => $size[0], 'map_height' => $size[1], 'map_origin_x' => -intdiv($size[0], 2), 'map_origin_y' => -intdiv($size[1], 2)];
    }
    public static function contains(array $node, int $x, int $y): bool
    {
        return $x >= (int)$node['map_origin_x'] && $y >= (int)$node['map_origin_y']
            && $x < (int)$node['map_origin_x'] + (int)$node['map_width'] && $y < (int)$node['map_origin_y'] + (int)$node['map_height'];
    }
    public static function assertCell(array $node, int $x, int $y): void
    {
        if (!self::contains($node, $x, $y)) throw new GameError('OUTSIDE_MAP', 'Ячейка находится за границами карты.', 422);
    }
    public static function resize(Connection $db, int $id, array $values): void
    {
        foreach (['map_width', 'map_height'] as $key) if (!is_int($values[$key]) || $values[$key] < 1 || $values[$key] > 1000) throw new GameError('INVALID_MAP_SIZE', 'Размер карты должен быть от 1 до 1000 ячеек.', 422);
        foreach ((new Query())->from('world_map_cell')->where(['parent_id' => $id])->all($db) as $cell) self::assertCell($values, (int)$cell['x'], (int)$cell['y']);
        foreach ((new Query())->from('world_node')->where(['parent_id' => $id])->andWhere(['<>', 'status', 'archived'])->all($db) as $child)
            foreach (WorldMapGeometry::cells($child['footprint_json'], (int)$child['position_x'], (int)$child['position_y']) as $cell) self::assertCell($values, $cell['x'], $cell['y']);
    }
}
