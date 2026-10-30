<?php
// No Composer, Yii, database, global clock or player data.
spl_autoload_register(static function (string $class): void {
    $prefix = 'common\\modules\\world\\domain\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../../common/modules/world/domain/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use common\modules\world\domain\{EntityReference, WorldEntity};
use common\modules\world\domain\characteristics\{Characteristics, CharacteristicSet, CombinedCharacteristics};
use common\modules\world\domain\events\{CropDamageCalculator, CropDamageRule, EventRule, EventRules, WorldEvent};

function eventCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
function eventReject(callable $action, string $message): void
{
    try { $action(); } catch (LogicException $error) { eventCheck(true, $message); return; }
    throw new RuntimeException($message);
}

$durability = new CharacteristicSet(['durability' => ['current' => 80, 'maximum' => 100]]);
$capacity = new CharacteristicSet(['storage' => ['slots' => 12]]);
$features = new CombinedCharacteristics($durability, $capacity);
$keys = [];
foreach (['node', 'item', 'event', 'future-kind'] as $kind) {
    $entity = new WorldEntity(new EntityReference($kind, 7), $features);
    $keys[] = $entity->reference->key();
    eventCheck($entity->characteristic('durability')['current'] === 80 && $entity->characteristic('storage')['slots'] === 12
        && $entity->characteristic('absent') === null, $kind . ' independently reuses the same feature implementations');
}
eventCheck(count(array_unique($keys)) === 4, 'references never conflate IDs of different entity kinds');
$copy = $features->all(); $copy['storage']['slots'] = 99;
eventCheck($features->all()['storage']['slots'] === 12, 'returned characteristic values do not mutate the snapshot');
eventReject(fn() => new CombinedCharacteristics($durability, $durability), 'duplicate groups require an explicit combination policy');
eventReject(fn() => new EntityReference('node', 0), 'invalid identity rejected');
eventReject(fn() => new CharacteristicSet(['Private Label' => []]), 'domain keys cannot be UI labels');
eventReject(fn() => new CharacteristicSet(['unsafe' => ['value' => new stdClass()]]), 'characteristics cannot retain mutable framework objects');
eventReject(fn() => new CharacteristicSet(['unsafe' => ['value' => INF]]), 'non-finite numeric values rejected');
$recursive = []; $recursive['self'] = &$recursive;
eventReject(fn() => new CharacteristicSet(['unsafe' => $recursive]), 'recursive characteristic structures rejected');

$registry = EventRules::standard();
$valid = '{"crop_damage":{"code":"light_crop_damage","loss_bps":2000}}';
$damage = $registry->characteristics($valid);
$event = new WorldEvent(1, 3, 'active', 100, 200, $damage);
eventCheck(!$event->activeAt(99) && $event->activeAt(100) && $event->activeAt(199) && !$event->activeAt(200), 'active interval includes start and excludes end');
eventCheck($event->affectsScope([8, 3, 1]) && !$event->affectsScope([9, 4, 1]), 'event scope reaches descendants but not neighbouring branches');
eventCheck(!$event->overlaps(200, 300, 250) && !$event->overlaps(1, 100, 250) && $event->overlaps(99, 101, 100), 'touching endpoints do not overlap and the current second is included');
eventCheck(!$event->overlaps(99, 300, 99), 'future event never influences the accepted interval');
eventCheck((new WorldEvent(2, 3, 'closed', 100, 200, $damage))->overlaps(99, 300, 200), 'naturally ended event retains historical effects');
eventCheck(!(new WorldEvent(2, 3, 'closed', 100, 200, $damage))->overlaps(99, 300, 199)
    && !(new WorldEvent(2, 3, 'closed', 100, null, $damage))->overlaps(99, 300, 250), 'early closed and open-ended closed events are excluded');
eventCheck(!(new WorldEvent(2, 3, 'draft', 100, 200, $damage))->overlaps(99, 300, 250), 'draft events have no effects');
eventReject(fn() => new WorldEvent(1, 3, 'active', 200, 200, $damage), 'empty lifecycle interval rejected');

// The persisted rules protocol and error messages remain byte-for-byte compatible.
$oldErrors = static function (?string $json): array {
    $rules = json_decode($json ?: '{}', true);
    if (!is_array($rules) || array_diff(array_keys($rules), ['crop_damage'])) return ['Ожидается JSON правил события.'];
    if (isset($rules['crop_damage']) && (!is_array($rules['crop_damage']) || ($rules['crop_damage']['code'] ?? null) !== 'light_crop_damage'
        || !is_int($rules['crop_damage']['loss_bps'] ?? null) || $rules['crop_damage']['loss_bps'] < 1 || $rules['crop_damage']['loss_bps'] > 2500)) {
        return ['Лёгкий урон урожаю: код light_crop_damage и loss_bps от 1 до 2500.'];
    }
    return [];
};
$inputs = [null, '', '{}', '[]', '0', 'null', 'false', '{', '[1]', '{"unknown":{}}', '{"crop_damage":null}', $valid];
foreach ([0, 1, 2500, 2501, '2000', 2000.5, true, null] as $loss) {
    $inputs[] = json_encode(['crop_damage' => ['code' => 'light_crop_damage', 'loss_bps' => $loss]]);
}
foreach ($inputs as $input) {
    if ($registry->errors($input) !== $oldErrors($input)) throw new RuntimeException('Legacy validation changed: ' . $input);
}
eventCheck(true, 'twenty persisted rule validation cases preserve the original contract');
eventCheck($registry->characteristics('{"unknown":{},"crop_damage":{"code":"light_crop_damage","loss_bps":500}}')->all()['crop.damage']['loss_bps'] === 500
    && $registry->characteristics('{"crop_damage":false}')->all() === [], 'legacy reads retain valid independent effects and ignore malformed effects');

// A new feature implementation extends events without editing WorldEntity or WorldEvent.
$speed = new class implements EventRule {
    public function key(): string { return 'speed'; }
    public function error(mixed $configuration): ?string { return is_array($configuration) && is_int($configuration['bps'] ?? null) ? null : 'Invalid speed.'; }
    public function characteristics(array $configuration): Characteristics { return new CharacteristicSet(['movement' => ['speed_bps' => $configuration['bps']]]); }
};
$extended = new EventRules(new CropDamageRule(), $speed);
$json = '{"speed":{"bps":11000},"crop_damage":{"code":"light_crop_damage","loss_bps":500}}';
$combinedEvent = new WorldEvent(3, 3, 'active', 100, null, $extended->characteristics($json));
eventCheck($extended->errors($json) === [] && $combinedEvent->characteristic('movement')['speed_bps'] === 11000
    && $combinedEvent->characteristic('crop.damage')['loss_bps'] === 500, 'new registered effects combine independently of event lifecycle');
eventReject(fn() => new EventRules($speed, $speed), 'duplicate rule registrations rejected');

$protection = ['code' => 'light_crop_damage', 'max_event_loss_bps' => 2500, 'loss_reduction_bps' => 5000];
$bed = new WorldEntity(new EntityReference('node', 8), new CombinedCharacteristics($durability, new CharacteristicSet(['event.protection' => $protection])));
$calculator = new CropDamageCalculator();
eventCheck($calculator->calculate([$event], $bed, [8, 3, 1], 90, 300, 150) === [
    'loss_bps' => 1000, 'effects' => [['event_id' => 1, 'raw_loss_bps' => 2000, 'loss_bps' => 1000]],
], 'an event effect and object protection interact through characteristics');
$unprotected = new WorldEntity(new EntityReference('node', 8), $durability);
$many = []; for ($i = 1; $i <= 7; $i++) $many[] = new WorldEvent($i, 3, 'active', 100, 200, $damage);
$capped = $calculator->calculate($many, $unprotected, [8, 3, 1], 90, 300, 150);
eventCheck($capped['loss_bps'] === 10000 && count($capped['effects']) === 7, 'combined loss is capped while preserving every event receipt');

// Compare the extracted calculation to the former SQL predicates and loop, with fixed pseudo-random inputs.
mt_srand(30102026);
for ($case = 0; $case < 500; $case++) {
    $from = mt_rand(100, 300); $until = $from + mt_rand(1, 300); $now = mt_rand($from, $until + 100);
    $expected = ['loss_bps' => 0, 'effects' => []]; $events = [];
    for ($id = 1; $id <= 12; $id++) {
        $start = mt_rand(1, 700); $end = mt_rand(0, 3) ? $start + mt_rand(1, 200) : null;
        $status = ['draft', 'active', 'closed'][mt_rand(0, 2)]; $scope = mt_rand(1, 4); $loss = mt_rand(1, 2500);
        $features = new CharacteristicSet(['crop.damage' => ['code' => 'light_crop_damage', 'loss_bps' => $loss]]);
        $events[] = new WorldEvent($id, $scope, $status, $start, $end, $features);
        if (!in_array($scope, [8, 3, 1], true) || !in_array($status, ['active', 'closed'], true)
            || $start >= min($now + 1, $until) || ($end !== null && $end <= $from)
            || ($status === 'closed' && ($end === null || $end > $now))) continue;
        $reduced = intdiv($loss * 5000, 10000);
        $expected['effects'][] = ['event_id' => $id, 'raw_loss_bps' => $loss, 'loss_bps' => $reduced];
        $expected['loss_bps'] = min(10000, $expected['loss_bps'] + $reduced);
    }
    if ($calculator->calculate($events, $bed, [8, 3, 1], $from, $until, $now) !== $expected) {
        throw new RuntimeException('Historical calculation changed in case ' . $case);
    }
}
eventCheck(true, '500 generated timelines preserve historical scope, closure, rounding and loss aggregation');
eventCheck(!class_exists('Yii', false), 'world event and characteristic domain runs without Yii');
echo 'World event domain checks passed.' . PHP_EOL;
