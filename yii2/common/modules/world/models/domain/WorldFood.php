<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\models\WorldTime;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** HP 0..100 is separate from night illness; food never erases an unprotected night. */
final class WorldFood
{
    public const HUNGER_AFTER = 259200;
    public const HUNGER_PERIOD = 86400;
    public const HUNGER_DAMAGE = 2;
    /** Old published profiles receive the same safe backpack rules until republished. */
    private static function rules(string $code, array $food): array
    {
        $defaults = ['world-cooked-carrot' => [4, 2, 21600], 'world-vegetable-broth' => [8, 1, 28800], 'world-travel-ration' => [6, 3, 43200]];
        $rule = $defaults[$code] ?? [min(8, (int)$food['restore_health']), 1, 21600];
        return ['restore_hp' => min(8, (int)($food['backpack_restore_hp'] ?? $rule[0])),
            'repeat_limit' => (int)($food['repeat_limit'] ?? $rule[1]), 'repeat_seconds' => (int)($food['repeat_seconds'] ?? $rule[2])];
    }
    public static function mealWindow(?int $now = null, ?Connection $db = null): array
    {
        $now = $now ?? time(); $settings = $db ? WorldTime::settings($db) : [];
        $now = $db ? WorldTime::gameAt($db, $now, null, $settings) : $now;
        $date = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $windows = [['breakfast', 'Завтрак', 6, 10], ['lunch', 'Обед', 12, 16], ['dinner', 'Ужин', 18, 22]];
        $current = null; $next = null;
        foreach ([0, 1] as $day) foreach ($windows as $window) {
            $start = $date->modify('+' . $day . ' day')->setTime($window[2], 0)->getTimestamp();
            $end = $date->modify('+' . $day . ' day')->setTime($window[3], 0)->getTimestamp();
            $entry = ['code' => $window[0], 'name' => $window[1], 'starts_at' => $db ? WorldTime::realAt($db, $start, null, $settings) : $start, 'ends_at' => $db ? WorldTime::realAt($db, $end, null, $settings) : $end];
            if ($start <= $now && $now < $end) $current = $entry;
            if ($next === null && $start > $now) $next = $entry;
        }
        return ['current' => $current, 'next' => $next, 'timezone' => 'Europe/Moscow'];
    }
    private static function events(Connection $db, int $user): Query
    {
        return (new Query())->from('world_health_event')->where(['world_id' => EconomyRuntime::world($db), 'user_id' => $user]);
    }
    private static function nutrition(Connection $db, int $user): ?array
    {
        $world = EconomyRuntime::world($db);
        $joined = (new Query())->select('joined_at')->from('world_membership')->where(['world_id' => $world, 'user_id' => $user])->scalar($db);
        if ($joined === false) return null;
        // Never charge time before the economy existed; later price revisions do not restart hunger.
        $published = (int)(new Query())->from('world_economy_profile')->min('created_at', $db);
        $meal = (int)(new Query())->from('world_health_event')->where(['world_id' => $world, 'user_id' => $user])->andWhere(['like', 'kind', 'food:%', false])->max('created_at', $db);
        return ['last_food_at' => $meal ?: null, 'anchor' => max((int)$joined, $published, $meal)];
    }
    public static function state(Connection $db, int $user): array
    {
        $revision = EconomyRuntime::current($db);
        $health = $revision ? (new Query())->from('world_player_health')->where(['world_id' => EconomyRuntime::world($db), 'user_id' => $user])->one($db) : null;
        $foods = [];
        if ($revision) {
            $store = new CraftStorage($db); $stock = [];
            if ($store->isCanonical()) {
                $backpack = (new CanonicalInventory($store))->backpack($user);
                $active = (new \common\modules\world\modules\craft\models\domain\CraftInventory($store))->capacity($user)['active_slots'];
                $rows = $backpack ? (new Query())->select(['item_id', 'quantity' => new \yii\db\Expression('SUM(item_quantity)')])->from('craft_inventory')
                    ->where(['storage_id' => $backpack['id'], 'user_id' => $user])->andWhere(['between', 'slot', 1, min($active, (int)$backpack['capacity'])])->groupBy('item_id')->all($db) : [];
                foreach ($rows as $row) $stock[(int)$row['item_id']] = (int)$row['quantity'];
            } else $stock = $store->quantities($user);
            $clockSettings = $revision['clock_settings']; $gameNow = WorldTime::gameAt($db, time(), null, $clockSettings);
            $events = self::events($db, $user)->andWhere(['like', 'kind', 'food:%', false])->andWhere(['>', 'created_at', WorldTime::realAt($db, $gameNow - 86400, null, $clockSettings)])->all($db);
            foreach ($revision['profile']['items'] as $code => $item) if (isset($item['restore_health'])) {
                $id = (int)(new Query())->select('id')->from('craft_item')->where(['code' => $code, 'active' => 1])->scalar($db);
                $worldRule = self::rules($code, $revision['raw_profile']['items'][$code]); $rule = self::rules($code, $item); $times = [];
                $cutoff = WorldTime::realAt($db, $gameNow - $worldRule['repeat_seconds'], null, $clockSettings);
                foreach ($events as $event) if (in_array($event['kind'], ['food:' . $code, 'food:disorder:' . $code], true) && (int)$event['created_at'] > $cutoff) $times[] = (int)$event['created_at'];
                sort($times); $used = count($times); $remaining = max(0, $rule['repeat_limit'] - $used);
                $restoreAt = $used >= $rule['repeat_limit'] ? WorldTime::realAt($db, WorldTime::gameAt($db, $times[$used - $rule['repeat_limit']], null, $clockSettings) + $worldRule['repeat_seconds'], null, $clockSettings) : null;
                $foods[] = ['code' => $code, 'name' => $item['name'], 'quantity' => $stock[$id] ?? 0, 'kitchen_restore_hp' => (int)$item['restore_health'],
                    'repeat_remaining' => $remaining, 'restores_at' => $restoreAt, 'can_eat' => $remaining > 0 && ($stock[$id] ?? 0) > 0] + $rule;
            }
        }
        $nutrition = $revision ? self::nutrition($db, $user) : null;
        $hp = $health ? (int)$health['hp'] : 100;
        $disorderUntil = 0;
        foreach ($events ?? [] as $event) if (strpos($event['kind'], 'food:disorder:') === 0) {
            $code = substr($event['kind'], 14); $definition = $revision['profile']['items'][$code] ?? null;
            if ($definition) $disorderUntil = max($disorderUntil, WorldTime::realAt($db, WorldTime::gameAt($db, (int)$event['created_at'], null, $clockSettings) + intdiv(self::rules($code, $revision['raw_profile']['items'][$code])['repeat_seconds'], 2), null, $clockSettings));
        }
        if ($disorderUntil <= time()) $disorderUntil = null;
        if ($disorderUntil) foreach ($foods as &$food) $food['can_eat'] = false;
        unset($food);
        return ['hp' => $hp, 'max_hp' => 100, 'revision' => $health ? (int)$health['revision'] : 0, 'foods' => $foods,
            'can_eat' => (bool)array_filter($foods, static function ($food) { return $food['can_eat']; }), 'disorder_until' => $disorderUntil,
            'last_food_at' => $nutrition ? $nutrition['last_food_at'] : null, 'server_time' => time()];
    }
    private static function change(Connection $db, int $user, int $delta, string $kind, string $operation, ?int $at = null): array
    {
        if (!$db->getTransaction()) throw new \LogicException('Health requires the game transaction.');
        $revision = EconomyRuntime::current($db);
        if (!$revision) return self::state($db, $user);
        if ((new Query())->from('world_health_event')->where(['operation_id' => $operation])->exists($db)) return self::state($db, $user);
        $state = self::state($db, $user); $where = ['world_id' => EconomyRuntime::world($db), 'user_id' => $user];
        $after = max(0, min(100, $state['hp'] + $delta));
        if ($state['revision']) {
            if ($db->createCommand()->update('world_player_health', ['hp' => $after, 'revision' => $state['revision'] + 1], $where + ['revision' => $state['revision']])->execute() !== 1) throw new \RuntimeException('Health changed concurrently.');
        } else $db->createCommand()->insert('world_player_health', $where + ['hp' => $after, 'revision' => 1])->execute();
        $db->createCommand()->insert('world_health_event', $where + ['operation_id' => $operation, 'hp_before' => $state['hp'], 'hp_after' => $after,
            'kind' => $kind, 'profile_id' => $revision['id'], 'created_at' => $at ?? time()])->execute();
        return self::state($db, $user);
    }
    /** Night jobs and food commands already hold the player lock. Reads never mutate HP. */
    public static function hunger(Connection $db, int $user, ?int $now = null): void
    {
        if (!$db->getTransaction()) throw new \LogicException('Hunger requires the player transaction.');
        if (!EconomyRuntime::current($db)) return;
        $nutrition = self::nutrition($db, $user); $now = $now ?? time();
        if (!$nutrition) return;
        $realNow = $now; $world = EconomyRuntime::world($db); $settings = WorldTime::settings($db);
        $now = WorldTime::gameAt($db, $now, null, $settings); $anchor = WorldTime::gameAt($db, $nutrition['anchor'], null, $settings);
        if ($now <= $anchor + self::HUNGER_AFTER) return;
        $due = intdiv($now - $anchor - self::HUNGER_AFTER - 1, self::HUNGER_PERIOD) + 1;
        $last = (int)(new Query())->from('world_health_event')->where(['world_id' => $world, 'user_id' => $user, 'kind' => 'hunger'])
            ->andWhere(['>', 'created_at', $nutrition['anchor']])->max('created_at', $db);
        $last = $last ? WorldTime::gameAt($db, $last, null, $settings) : 0;
        $paid = $last > $anchor + self::HUNGER_AFTER ? intdiv($last - $anchor - self::HUNGER_AFTER - 1, self::HUNGER_PERIOD) + 1 : 0;
        if ($due <= $paid) return;
        $operation = bin2hex(random_bytes(16));
        $db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $user, 'type' => 'world.health.hunger', 'created_at' => $realNow]
            + EconomyRuntime::operationContext($db, []))->execute();
        self::change($db, $user, -self::HUNGER_DAMAGE * ($due - $paid), 'hunger', $operation, WorldTime::realAt($db, $anchor + self::HUNGER_AFTER + ($due - 1) * self::HUNGER_PERIOD + 1, null, $settings));
    }
    public static function night(Connection $db, int $user, bool $protected, string $operation): void
    {
        if (!$protected && EconomyRuntime::current($db)) self::change($db, $user, -15, 'unprotected_night', $operation);
    }
    public static function kitchen(Connection $db, int $user, int $node): array
    {
        $place = Node::visibleTo($user)->andWhere(['id' => $node, 'root_id' => EconomyRuntime::world($db), 'status' => 'active'])->asArray()->one($db);
        $isKitchen = $place && (new Query())->from('world_template')->where(['id' => $place['template_id'], 'code' => 'field-kitchen'])->exists($db);
        if (!$isKitchen || !NodeAvailability::activePath($db, $node)) throw new GameError('KITCHEN_UNAVAILABLE', 'Выберите действующую доступную полевую кухню.', 403);
        $meal = self::mealWindow(null, $db); $current = $meal['current'];
        $used = $current && self::events($db, $user)->andWhere(['kind' => 'food:kitchen:' . $current['code']])
            ->andWhere(['>=', 'created_at', $current['starts_at']])->andWhere(['<', 'created_at', $current['ends_at']])->exists($db);
        return ['node_id' => $node, 'meal' => $meal, 'meal_used' => (bool)$used, 'health' => self::state($db, $user), 'server_time' => time()];
    }
    public static function consume(Connection $db, int $user, string $code, int $expected, string $operation, ?int $kitchen = null): array
    {
        $revision = EconomyRuntime::current($db); $food = $revision['profile']['items'][$code] ?? null;
        if (!$food || !isset($food['restore_health'])) throw new GameError('NOT_FOOD', 'Выберите еду из действующего каталога.', 422);
        $state = self::state($db, $user);
        if ($expected !== $state['revision']) throw new GameError('HEALTH_CHANGED', 'Здоровье изменилось. Проверьте результат перед применением.', 409);
        self::hunger($db, $user);
        $state = self::state($db, $user);
        $selected = null; foreach ($state['foods'] as $candidate) if ($candidate['code'] === $code) $selected = $candidate;
        if (!$selected || !$selected['quantity']) throw new GameError('FOOD_UNAVAILABLE', 'Положите порцию этого блюда в рюкзак.', 409);
        $meal = $kitchen === null ? null : self::kitchen($db, $user, $kitchen);
        if ($meal && (!$meal['meal']['current'] || $meal['meal_used'])) throw new GameError('MEAL_UNAVAILABLE', $meal['meal_used'] ? 'Этот приём пищи уже использован сегодня.' : 'Кухня принимает гостей в часы завтрака, обеда и ужина.', 409);
        if (!$meal && $state['disorder_until']) throw new GameError('FOOD_DISORDER', 'Пищевое расстройство: дождитесь восстановления или поешьте в полевой кухне.', 409);
        $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1, 'kind' => 'consumable'])->one($db);
        if (!$item) throw new GameError('FOOD_UNAVAILABLE', 'Это блюдо временно недоступно.', 409);
        $store = new CraftStorage($db); $store->operationId = $operation; $store->move($user, $item, -1);
        $over = !$meal && $selected['repeat_remaining'] === 0;
        $delta = $meal ? (int)$food['restore_health'] : ($over ? -4 : $selected['restore_hp']);
        $kind = $meal ? 'food:kitchen:' . $meal['meal']['current']['code'] : ($over ? 'food:disorder:' . $code : 'food:' . $code);
        $result = self::change($db, $user, $delta, $kind, $operation);
        $db->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => 'food', 'item_id' => $item['id'], 'quantity' => 1, 'credit_change' => 0, 'created_at' => time()])->execute();
        return ['health' => $result, 'restored_hp' => $result['hp'] - $state['hp'], 'food_name' => $food['name'], 'consumed_quantity' => 1,
            'message' => $over ? 'Слишком много одинаковой еды: пищевое расстройство, −4 HP. Следите за разнообразием блюд.' : 'Порция съедена.', 'changed_node_ids' => $kitchen ? [$kitchen] : []];
    }
}
