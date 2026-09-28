<?php
namespace common\modules\world\service;

use common\services\game\CanonicalJson;
use common\services\game\GameError;

/** Absolute integer grid vertices. A null polygon occupies exactly the anchor cell. */
final class WorldMapGeometry
{
    public static function normalize(?string $json, int $x, int $y): ?string
    {
        if ($json === null || trim($json) === '') return null;
        try { $vertices = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new GameError('INVALID_FOOTPRINT', 'Полигон должен быть JSON-массивом точек.', 422); }
        if (!is_array($vertices) || count($vertices) < 3 || count($vertices) > 32 || array_keys($vertices) !== range(0, count($vertices) - 1))
            throw new GameError('INVALID_FOOTPRINT', 'Полигон должен содержать от 3 до 32 точек.', 422);
        $points = []; $xs = []; $ys = [];
        foreach ($vertices as $point) {
            if (!is_array($point) || !is_int($point['x'] ?? null) || !is_int($point['y'] ?? null)
                || abs($point['x']) > 1000000 || abs($point['y']) > 1000000)
                throw new GameError('INVALID_FOOTPRINT', 'Координаты точек полигона должны быть целыми числами.', 422);
            $points[] = ['x' => $point['x'], 'y' => $point['y']]; $xs[] = $point['x']; $ys[] = $point['y'];
        }
        if (min($xs) !== $x || min($ys) !== $y || max($xs) - min($xs) > 50 || max($ys) - min($ys) > 50
            || max($xs) === min($xs) || max($ys) === min($ys))
            throw new GameError('INVALID_FOOTPRINT', 'Полигон должен начинаться в координатах объекта и занимать не более 50×50 ячеек.', 422);
        $doubleArea = 0;
        for ($i = 0, $count = count($points); $i < $count; $i++) {
            $a = $points[$i]; $b = $points[($i + 1) % $count];
            if ($a === $b) throw new GameError('INVALID_FOOTPRINT', 'Соседние вершины полигона не должны совпадать.', 422);
            $doubleArea += $a['x'] * $b['y'] - $b['x'] * $a['y'];
        }
        if ($doubleArea === 0) throw new GameError('INVALID_FOOTPRINT', 'Площадь полигона должна быть положительной.', 422);
        for ($i = 0, $count = count($points); $i < $count; $i++) for ($j = $i + 2; $j < $count; $j++) {
            if ($i === 0 && $j === $count - 1) continue;
            if (self::intersects($points[$i], $points[($i + 1) % $count], $points[$j], $points[($j + 1) % $count]))
                throw new GameError('INVALID_FOOTPRINT', 'Границы полигона не должны пересекаться.', 422);
        }
        $encoded = CanonicalJson::encode($points);
        self::cells($encoded, $x, $y);
        return $encoded;
    }
    private static function cross(array $a, array $b, array $c): int
    { return ($b['x'] - $a['x']) * ($c['y'] - $a['y']) - ($b['y'] - $a['y']) * ($c['x'] - $a['x']); }
    private static function onSegment(array $a, array $b, array $p): bool
    { return min($a['x'], $b['x']) <= $p['x'] && $p['x'] <= max($a['x'], $b['x']) && min($a['y'], $b['y']) <= $p['y'] && $p['y'] <= max($a['y'], $b['y']); }
    private static function intersects(array $a, array $b, array $c, array $d): bool
    {
        $abc = self::cross($a, $b, $c); $abd = self::cross($a, $b, $d);
        $cda = self::cross($c, $d, $a); $cdb = self::cross($c, $d, $b);
        return (($abc > 0 && $abd < 0) || ($abc < 0 && $abd > 0)) && (($cda > 0 && $cdb < 0) || ($cda < 0 && $cdb > 0))
            || ($abc === 0 && self::onSegment($a, $b, $c)) || ($abd === 0 && self::onSegment($a, $b, $d))
            || ($cda === 0 && self::onSegment($c, $d, $a)) || ($cdb === 0 && self::onSegment($c, $d, $b));
    }
    public static function covers(?string $json, int $anchorX, int $anchorY, int $x, int $y): bool
    {
        if ($json === null || $json === '') return $anchorX === $x && $anchorY === $y;
        $points = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $inside = false; $px = $x + 0.5; $py = $y + 0.5;
        for ($i = 0, $j = count($points) - 1; $i < count($points); $j = $i++) {
            $a = $points[$i]; $b = $points[$j];
            if (($a['y'] > $py) !== ($b['y'] > $py)
                && $px < ($b['x'] - $a['x']) * ($py - $a['y']) / ($b['y'] - $a['y']) + $a['x']) $inside = !$inside;
        }
        return $inside;
    }
    public static function cells(?string $json, int $x, int $y): array
    {
        if ($json === null || $json === '') return [['x' => $x, 'y' => $y]];
        $points = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $xs = array_column($points, 'x'); $ys = array_column($points, 'y'); $cells = [];
        for ($cy = min($ys); $cy < max($ys); $cy++) for ($cx = min($xs); $cx < max($xs); $cx++)
            if (self::covers($json, $x, $y, $cx, $cy)) $cells[] = ['x' => $cx, 'y' => $cy];
        if (!$cells || !self::covers($json, $x, $y, $x, $y)) throw new GameError('INVALID_FOOTPRINT', 'Полигон должен включать ячейку объекта.', 422);
        return $cells;
    }
}
