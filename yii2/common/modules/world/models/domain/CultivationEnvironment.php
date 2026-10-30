<?php
namespace common\modules\world\models\domain;

use common\modules\world\domain\EntityReference;
use common\modules\world\domain\WorldEntity;
use common\modules\world\domain\characteristics\CharacteristicSet;
use common\modules\world\domain\events\CropDamageCalculator;
use common\modules\world\domain\events\EventRules;
use common\modules\world\domain\events\WorldEvent;
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
        $ancestors = array_map('intval', (new Query())->select('ancestor_id')->from('world_node_closure')
            ->where(['descendant_id' => $cycle['bed_id']])->column($db));
        $events = (new Query())->from('world_event')->where(['scope_node_id' => $ancestors, 'status' => ['active', 'closed']])->andWhere(['<', 'starts_at', min($now + 1, (int)$cycle['ready_at'])])
            ->andWhere(['or', ['ends_at' => null], ['>', 'ends_at', (int)$cycle['planted_at']]])->orderBy('id')->all($db);
        $definitions = [];
        $rules = EventRules::standard();
        foreach ($events as $event) {
            $definitions[] = new WorldEvent(
                (int)$event['id'], (int)$event['scope_node_id'], $event['status'], (int)$event['starts_at'],
                $event['ends_at'] === null ? null : (int)$event['ends_at'],
                $rules->characteristics($event['rules_json'])
            );
        }
        $features = empty($environment['event_protection']) ? [] : ['event.protection' => $environment['event_protection']];
        $bed = new WorldEntity(new EntityReference('node', (int)$cycle['bed_id']), new CharacteristicSet($features));
        return (new CropDamageCalculator())->calculate(
            $definitions, $bed, $ancestors, (int)$cycle['planted_at'], (int)$cycle['ready_at'], $now
        );
    }
}
