<?php
namespace common\modules\world\models\domain;

/** Integer UTC seconds. Sequence zero is the first night after activation. */
class NightCalendar
{
    public static function bounds(array $policy, int $sequence): array
    {
        if ($sequence < 0) throw new \InvalidArgumentException('Negative night sequence.');
        $start = (int)$policy['activated_at'] + $sequence * (int)$policy['day_seconds'] + (int)$policy['night_offset'];
        $duration = (int)$policy['night_seconds'];
        foreach ($policy['time_segments'] ?? [] as $segment) {
            if ($sequence < $segment['from_sequence']) break;
            $start = (int)$segment['started_at'] + ($sequence - (int)$segment['from_sequence']) * (int)$segment['day_seconds'];
            $duration = (int)$segment['night_seconds'];
        }
        return ['sequence' => $sequence, 'started_at' => $start, 'ended_at' => $start + $duration];
    }
    public static function firstStartingAt(array $policy, int $instant): int
    {
        $low = 0; $high = max(1, $instant - (int)$policy['activated_at'] + 1);
        while ($low < $high) { $middle = intdiv($low + $high, 2); if (self::bounds($policy, $middle)['started_at'] < $instant) $low = $middle + 1; else $high = $middle; }
        return $low;
    }
    public static function currentOrNext(array $policy, int $now): array
    {
        $low = 0; $high = max(1, $now - (int)$policy['activated_at'] + 1);
        while ($low < $high) { $middle = intdiv($low + $high, 2); if (self::bounds($policy, $middle)['ended_at'] <= $now) $low = $middle + 1; else $high = $middle; }
        return self::bounds($policy, $low);
    }
    public static function efficiency(array $policy, int $severity): int
    {
        if ($severity === 0) return 10000;
        return (int)$policy['mild_efficiency_bps'] - intdiv(((int)$policy['mild_efficiency_bps'] - (int)$policy['severe_efficiency_bps']) * ($severity - 1), (int)$policy['max_severity'] - 1);
    }
    public static function advance(array $policy, array $health, bool $protected, int $nightEnd): array
    {
        $severity = (int)$health['severity']; $progress = (int)$health['recovery_progress']; $onset = $health['onset_at']; $exposure = (int)$health['exposure_nights'];
        if (!$protected) {
            if (!$severity) $onset = $nightEnd;
            $severity = min((int)$policy['max_severity'], $severity + 1); $progress = 0; $exposure++;
        } elseif ($severity > 0) {
            $progress++;
            if ($progress >= (int)$policy['recovery_nights']) { $severity--; $progress = 0; }
            if (!$severity) $onset = null;
        }
        return ['severity' => $severity, 'recovery_progress' => $progress, 'onset_at' => $onset, 'exposure_nights' => $exposure];
    }
}
