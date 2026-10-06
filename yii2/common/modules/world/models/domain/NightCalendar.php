<?php
namespace common\modules\world\models\domain;

/** Integer UTC seconds. Sequence zero is the first night after activation. */
class NightCalendar
{
    public static function bounds(array $policy, int $sequence): array
    {
        if ($sequence < 0) throw new \InvalidArgumentException('Negative night sequence.');
        $start = (int)$policy['activated_at'] + $sequence * (int)$policy['day_seconds'] + (int)$policy['night_offset'];
        return ['sequence' => $sequence, 'started_at' => $start, 'ended_at' => $start + (int)$policy['night_seconds']];
    }
    public static function firstStartingAt(array $policy, int $instant): int
    {
        $delta = max(0, $instant - (int)$policy['activated_at'] - (int)$policy['night_offset']);
        return intdiv($delta + (int)$policy['day_seconds'] - 1, (int)$policy['day_seconds']);
    }
    public static function currentOrNext(array $policy, int $now): array
    {
        $firstEnd = (int)$policy['activated_at'] + (int)$policy['night_offset'] + (int)$policy['night_seconds'];
        $sequence = $now < $firstEnd ? 0 : intdiv($now - $firstEnd, (int)$policy['day_seconds']) + 1;
        return self::bounds($policy, $sequence);
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
