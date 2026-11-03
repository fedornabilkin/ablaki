<?php
namespace common\modules\world\models\domain;

/** Versioned schedule accepted at sowing. Existing crops keep their original rules. */
final class CultivationSchedule
{
    public static function forSowing(array $rules): array
    {
        return ['version' => 2, 'waterings' => $rules['water_item_id'] === null ? 0 : 2,
            'interval_seconds' => max(1, min((int)$rules['water_interval_seconds'], intdiv((int)$rules['grow_seconds'], 2)))];
    }

    public static function accepted(array $cycle): ?array
    {
        $terms = json_decode($cycle['terms_json'] ?? '{}', true) ?: [];
        $schedule = $terms['watering_schedule'] ?? null;
        return is_array($schedule) && ($schedule['version'] ?? null) === 2 ? $schedule : null;
    }

    public static function step(array $cycle): ?int
    {
        $schedule = self::accepted($cycle);
        if (!$schedule || !$schedule['waterings'] || $cycle['water_due_at'] === null) return null;
        return (int)$cycle['water_due_at'] <= (int)$cycle['planted_at'] ? 1 : 2;
    }

    public static function nextWatering(array $cycle, array $rules): ?int
    {
        $schedule = self::accepted($cycle);
        if ($schedule) return self::step($cycle) === 1 ? (int)$cycle['planted_at'] + $schedule['interval_seconds'] : null;
        $next = (int)$cycle['water_due_at'] + (int)$rules['water_interval_seconds'];
        return $next < (int)$cycle['ready_at'] ? $next : null;
    }
}
