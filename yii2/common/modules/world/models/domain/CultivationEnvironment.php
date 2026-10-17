<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** A sowing snapshots shelter benefits; later moves or tariff changes do not alter the cycle. */
final class CultivationEnvironment
{
    public static function snapshot(Connection $db, int $bed, int $user): array
    {
        $neutral = ['grow_duration_bps' => 10000, 'event_protection' => null];
        if (!EconomyRuntime::current($db)) return $neutral;
        $rows = (new Query())->select(['n.id', 'n.settings_json'])->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], 'c.ancestor_id=n.id')
            ->innerJoin(['t' => 'world_template'], 't.id=n.template_id')->where(['c.descendant_id' => $bed, 'n.owner_user_id' => $user, 'n.status' => 'active', 't.code' => 'small-greenhouse'])->orderBy(['c.distance' => SORT_ASC])->all($db);
        foreach ($rows as $row) if (NodeAvailability::activePath($db, (int)$row['id'])) {
            $settings = json_decode($row['settings_json'], true) ?: [];
            if (isset($settings['grow_duration_bps'], $settings['event_protection'])) return array_intersect_key($settings, $neutral) + ['greenhouse_node_id' => (int)$row['id']];
        }
        return $neutral;
    }
    public static function rules(array $rules, array $environment): array
    {
        $bps = $environment['grow_duration_bps'] ?? 10000;
        foreach (['grow_seconds', 'water_interval_seconds', 'water_window_seconds'] as $field) if ((int)$rules[$field] > 0) $rules[$field] = max(1, intdiv((int)$rules[$field] * $bps + 9999, 10000));
        return $rules;
    }
    public static function accepted(?array $cycle): array { return $cycle ? ((json_decode($cycle['terms_json'], true) ?: [])['environment'] ?? ['grow_duration_bps' => 10000, 'event_protection' => null]) : []; }
    public static function damage(Connection $db, array $cycle, array $environment, int $now): array
    {
        $ancestors = (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $cycle['bed_id']]);
        $events = (new Query())->from('world_event')->where(['scope_node_id' => $ancestors, 'status' => ['active', 'closed']])->andWhere(['<', 'starts_at', min($now + 1, (int)$cycle['ready_at'])])
            ->andWhere(['or', ['ends_at' => null], ['>', 'ends_at', (int)$cycle['planted_at']]])->orderBy('id')->all($db);
        $effects = []; $total = 0;
        foreach ($events as $event) {
            $damage = (json_decode($event['rules_json'], true) ?: [])['crop_damage'] ?? null;
            if (!is_array($damage) || ($damage['code'] ?? null) !== 'light_crop_damage' || !is_int($damage['loss_bps'] ?? null) || $damage['loss_bps'] < 1 || $damage['loss_bps'] > 2500) continue;
            if ($event['status'] === 'closed' && ($event['ends_at'] === null || (int)$event['ends_at'] > $now)) continue;
            $protection = $environment['event_protection'] ?? null; $loss = $damage['loss_bps'];
            if ($protection && $protection['code'] === $damage['code'] && $loss <= $protection['max_event_loss_bps']) $loss = intdiv($loss * (10000 - $protection['loss_reduction_bps']), 10000);
            $effects[] = ['event_id' => (int)$event['id'], 'raw_loss_bps' => $damage['loss_bps'], 'loss_bps' => $loss]; $total = min(10000, $total + $loss);
        }
        return ['loss_bps' => $total, 'effects' => $effects];
    }
}
