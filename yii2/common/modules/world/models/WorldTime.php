<?php
namespace common\modules\world\models;

/** Build durations remain real seconds; the world clock only changes their presentation/input. */
final class WorldTime
{
    public const DEFAULT_RATE = 60;
    public static function rate(?int $root = null): int
    {
        $id = $root ?: Registry::worldId();
        $node = $id ? Node::findOne($id) : null;
        $settings = $node ? json_decode((string)$node->settings_json, true) : [];
        $value = $settings['world_time_rate'] ?? self::DEFAULT_RATE;
        return is_numeric($value) && (int)$value >= 1 && (int)$value <= 86400 ? (int)$value : self::DEFAULT_RATE;
    }
    public static function realSeconds(int $worldSeconds, int $rate): int { return (int)ceil($worldSeconds / $rate); }
    public static function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86400); $seconds %= 86400;
        return ($days ? $days . ' д. ' : '') . sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
