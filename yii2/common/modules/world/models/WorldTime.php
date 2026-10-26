<?php
namespace common\modules\world\models;

/** Continuous world clock. Audit timestamps and transport/worker leases remain real time. */
final class WorldTime
{
    public const DEFAULT_RATE = 1;
    public static function settings(\yii\db\Connection $db, ?int $root = null): array
    {
        if (!$db->schema->getTableSchema('world_registry')) return [];
        $schema = $db->schema->getTableSchema('world_node');
        if (!$schema || !isset($schema->columns['settings_json'])) return [];
        $root = $root ?: (int)(new \yii\db\Query())->select('active_world_id')->from('world_registry')->where(['id' => 1])->scalar($db);
        $json = $root ? (new \yii\db\Query())->select('settings_json')->from('world_node')->where(['id' => $root])->scalar($db) : null;
        return json_decode($json ?: '{}', true) ?: [];
    }
    public static function rate(?int $root = null): int
    {
        $id = $root ?: Registry::worldId();
        $node = $id ? Node::findOne($id) : null;
        $settings = $node ? json_decode((string)$node->settings_json, true) : [];
        $value = $settings['world_time_rate'] ?? self::DEFAULT_RATE;
        return is_numeric($value) && (int)$value >= 1 && (int)$value <= 86400 ? (int)$value : self::DEFAULT_RATE;
    }
    public static function realSeconds(int $worldSeconds, int $rate): int { return (int)ceil($worldSeconds / $rate); }
    public static function gameAt(\yii\db\Connection $db, int $real, ?int $root = null, ?array $settings = null): int
    {
        $segments = ($settings ?? self::settings($db, $root))['world_clock_segments'] ?? [['real_at' => 0, 'game_at' => 0, 'rate' => 1]];
        $chosen = ['real_at' => 0, 'game_at' => 0, 'rate' => 1];
        foreach ($segments as $segment) { if ((int)$segment['real_at'] > $real) break; $chosen = $segment; }
        return (int)$chosen['game_at'] + ($real - (int)$chosen['real_at']) * (int)$chosen['rate'];
    }
    public static function realAt(\yii\db\Connection $db, int $game, ?int $root = null, ?array $settings = null): int
    {
        $segments = ($settings ?? self::settings($db, $root))['world_clock_segments'] ?? [['real_at' => 0, 'game_at' => 0, 'rate' => 1]];
        $chosen = ['real_at' => 0, 'game_at' => 0, 'rate' => 1];
        foreach ($segments as $segment) { if ((int)$segment['game_at'] > $game) break; $chosen = $segment; }
        return (int)$chosen['real_at'] + (int)ceil(($game - (int)$chosen['game_at']) / (int)$chosen['rate']);
    }
    public static function dayWindow(\yii\db\Connection $db, ?int $real = null): array
    {
        $settings = self::settings($db); $game = self::gameAt($db, $real ?? time(), null, $settings);
        $start = (new \DateTimeImmutable('@' . $game))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        return ['key' => $start->format('Ymd'), 'start' => self::realAt($db, $start->getTimestamp(), null, $settings), 'end' => self::realAt($db, $start->modify('+1 day')->getTimestamp(), null, $settings)];
    }
    /** New operations snapshot scaled durations. Already accepted jobs keep their terms. */
    public static function runtimeProfile(\yii\db\Connection $db, array $profile, ?array $settings = null): array
    {
        $rate = (int)(($settings ?? self::settings($db))['world_time_rate'] ?? 1);
        if ($rate === 1) return $profile;
        $scale = static function (array $values) use (&$scale, $rate): array {
            foreach ($values as $key => &$value) {
                if (is_array($value)) $value = $scale($value);
                elseif (is_int($value) && $value > 0 && substr((string)$key, -8) === '_seconds') $value = max(1, self::realSeconds($value, $rate));
            } unset($value); return $values;
        };
        return $scale($profile);
    }
    /** Called under the standard administrative catalog/registry locks and transaction. */
    public static function change(Node $node, array $old, array $settings): array
    {
        $rate = (int)($settings['world_time_rate'] ?? 1); $previous = (int)($old['world_time_rate'] ?? 1);
        if ($rate < 1 || $rate > 86400) throw new \InvalidArgumentException('World rate must be between 1 and 86400.');
        // Anchors are server-owned; editing raw JSON cannot rewrite past elapsed time.
        foreach (['world_clock_segments', 'world_night_segments'] as $key) {
            if (isset($old[$key])) $settings[$key] = $old[$key]; else unset($settings[$key]);
        }
        if ($rate === $previous) return $settings;
        $db = Node::getDb(); $now = time(); $game = self::gameAt($db, $now, (int)$node->id);
        $settings['world_clock_segments'] = $old['world_clock_segments'] ?? [['real_at' => 0, 'game_at' => 0, 'rate' => 1]];
        $settings['world_clock_segments'][] = ['real_at' => $now, 'game_at' => $game, 'rate' => $rate];
        $policy = (new \yii\db\Query())->from('world_night_policy')->where(['world_id' => $node->id, 'version' => 1])->one($db);
        if ($policy) {
            $policy['time_segments'] = $old['world_night_segments'] ?? [];
            $sequence = \common\modules\world\models\domain\NightCalendar::firstStartingAt($policy, $now + 1);
            $last = (new \yii\db\Query())->select('sequence')->from('world_night_period')->where(['policy_id' => $policy['id']])->max('sequence', $db);
            $sequence = max($sequence, $last === null ? 0 : (int)$last + 1);
            $start = \common\modules\world\models\domain\NightCalendar::bounds($policy, $sequence)['started_at'];
            $worldStart = self::gameAt($db, $start, (int)$node->id, $old);
            $start = self::realAt($db, $worldStart, (int)$node->id, $settings);
            $current = \common\modules\world\models\domain\NightCalendar::currentOrNext($policy, $now);
            if ($current['started_at'] <= $now) $start = max($start, $current['ended_at']);
            $segments = array_values(array_filter($old['world_night_segments'] ?? [], static function ($segment) use ($sequence) { return $segment['from_sequence'] < $sequence; }));
            $segments[] = ['from_sequence' => $sequence, 'started_at' => $start, 'day_seconds' => max(1, self::realSeconds((int)$policy['day_seconds'], $rate)), 'night_seconds' => max(1, self::realSeconds((int)$policy['night_seconds'], $rate))];
            $settings['world_night_segments'] = $segments;
            $policy['time_segments'] = $segments;
            foreach ((new \yii\db\Query())->select(['id', 'payload_json'])->from('game_job')->where(['type' => 'world.night.resolve', 'status' => 'pending'])->all($db) as $job) {
                $payload = json_decode($job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if ((int)$payload['policy_id'] !== (int)$policy['id']) continue;
                $cursor = (int)$payload['cursor']; $at = $now;
                if ($cursor > 0) {
                    $health = (new \yii\db\Query())->select('grace_until')->from('actor_night_health')->where(['membership_id' => $payload['membership_id'], 'policy_id' => $policy['id']])->one($db);
                    if ($health) $cursor = max($cursor, \common\modules\world\models\domain\NightCalendar::firstStartingAt($policy, (int)$health['grace_until']));
                    $at = max($now, \common\modules\world\models\domain\NightCalendar::bounds($policy, $cursor)['ended_at']);
                }
                $db->createCommand()->update('game_job', ['available_at' => $at], ['id' => $job['id'], 'status' => 'pending'])->execute();
            }
        }
        return $settings;
    }
    public static function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86400); $seconds %= 86400;
        return ($days ? $days . ' д. ' : '') . sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
