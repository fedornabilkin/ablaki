<?php
namespace common\modules\world\models\domain;

/** Read-only clock shared by previews and execution; growth never waits for login. */
class CultivationClock
{
    public static function project(array $cycle, array $rules, int $now): array
    {
        $ready = (int)$cycle['ready_at'];
        $due = $cycle['water_due_at'] === null ? null : (int)$cycle['water_due_at'];
        $deadline = $due === null || $due >= $ready ? null : min($ready, $due + (int)$rules['water_window_seconds']);
        $missed = !empty($cycle['water_missed']) || ($deadline !== null && $now >= $deadline);
        $expires = $ready + (int)$rules['harvest_window_seconds'];
        $canWater = $due !== null && $due < $ready && $now >= $due && $now < $ready;
        return ['state' => $now >= $expires ? 'expired' : ($now >= $ready ? 'ripe' : ($canWater ? 'needs_water' : 'growing')),
            'ready_at' => $ready, 'water_due_at' => $due, 'water_deadline_at' => $deadline, 'expires_at' => $expires,
            'water_missed' => $missed, 'yield_factor_bps' => $missed ? 5000 : 10000, 'can_water' => $canWater];
    }
}
