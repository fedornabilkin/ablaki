<?php
// Disposable allowlisted fixture only. Never loads the application's database configuration.
require __DIR__ . '/world-economy-runtime.php';

use common\modules\world\domain\events\EventRules;
use common\modules\world\models\WorldEvent;
use common\modules\world\models\domain\CultivationEnvironment;

$transaction = $db->beginTransaction();
try {
    $db->createCommand()->delete('world_event')->execute();
    // The runtime fixture archives this ancestor after checking accepted greenhouse benefits.
    $db->createCommand()->update('world_node', ['status' => 'active'], ['id' => $greenhouse->id])->execute();
    $now = time();
    $bedId = (int)$greenhouseBed->id;
    $cycle = ['bed_id' => $bedId, 'planted_at' => $now - 100, 'ready_at' => $now + 100];
    $json = static fn(int $loss): string => json_encode(['crop_damage' => ['code' => 'light_crop_damage', 'loss_bps' => $loss]]);
    $add = static function (string $name, int $scope, string $status, int $start, ?int $end, string $rules) use ($db): int {
        $event = new WorldEvent();
        $event->loadDefaultValues();
        $event->setAttributes(['name' => $name, 'scope_node_id' => $scope, 'status' => $status, 'starts_at' => $start, 'ends_at' => $end, 'rules_json' => $rules], false);
        if (!$event->save(false)) throw new RuntimeException('Event fixture write failed.');
        return (int)$event->id;
    };
    $local = $add('Local', $bedId, 'active', $now - 50, $now + 50, $json(2000));
    $parent = $add('Ancestor', (int)$greenhouse->id, 'active', $now - 50, $now + 50, $json(500));
    $neighbour = $add('Neighbour', (int)$forestId, 'active', $now - 50, $now + 50, $json(2500));
    $draft = $add('Draft', $bedId, 'draft', $now - 50, $now + 50, $json(2500));
    $future = $add('Future', $bedId, 'active', $now + 600, null, $json(2500));
    $add('At harvest', $bedId, 'active', $cycle['ready_at'], null, $json(2500));
    $add('Before sowing', $bedId, 'active', $now - 200, $cycle['planted_at'], $json(2500));
    $finished = $add('Ended naturally', $bedId, 'closed', $now - 50, $now - 10, $json(1500));
    $closed = $add('Closed early', $bedId, 'closed', $now - 50, $now + 50, $json(2500));
    $add('Closed without deadline', $bedId, 'closed', $now - 50, null, $json(2500));
    $open = $add('No deadline', $bedId, 'active', $now - 50, null, $json(501));
    $add('Broken old rule', $bedId, 'active', $now - 50, null, '{"crop_damage":false}');
    $mixed = $add('Unknown and supported rules', $bedId, 'active', $now - 50, null, '{"unknown":{},"crop_damage":{"code":"light_crop_damage","loss_bps":100}}');

    $journalCounts = static function () use ($db): array {
        $counts = [];
        foreach (['game_operation', 'game_outbox', 'inventory_movement', 'craft_command'] as $table) {
            $counts[$table] = (int)(new \yii\db\Query())->from($table)->count('*', $db);
        }
        return $counts;
    };
    $before = $journalCounts();
    $accepted = ['event_protection' => ['code' => 'light_crop_damage', 'max_event_loss_bps' => 2500, 'loss_reduction_bps' => 5000]];
    $result = CultivationEnvironment::damage($db, $cycle, $accepted, $now);
    $expected = [[$local, 2000, 1000], [$parent, 500, 250], [$finished, 1500, 750], [$open, 501, 250], [$mixed, 100, 50]];
    economyCheck($result === ['loss_bps' => 2300, 'effects' => array_map(static fn(array $row): array => [
        'event_id' => $row[0], 'raw_loss_bps' => $row[1], 'loss_bps' => $row[2],
    ], $expected)], 'event adapter preserves ordered receipts, scope, deadlines, closure and integer protection');
    economyCheck(CultivationEnvironment::damage($db, $cycle, ['event_protection' => null], $now)['loss_bps'] === 4601,
        'accepted protection is optional and never inferred from unrelated characteristics');
    economyCheck(CultivationEnvironment::damage($db, $cycle, $accepted, $now) === $result && $journalCounts() === $before,
        'repeated event evaluation has no writes, notifications or economic side effects');

    $visible = WorldEvent::atNode($bedId, $freshUser)->all();
    $visibleIds = array_map(static fn(WorldEvent $event): int => (int)$event->id, $visible);
    economyCheck(in_array($local, $visibleIds, true) && in_array($parent, $visibleIds, true)
        && !array_intersect([$neighbour, $draft, $future, $finished, $closed], $visibleIds), 'existing event listing retains active ancestral scope');
    economyCheck(array_keys($visible[0]->toArray()) === ['id', 'name', 'scope_node_id', 'description', 'starts_at', 'ends_at', 'status'],
        'public event fields remain unchanged and do not expose internal rules');

    foreach (['{}', '{', '{"unknown":{}}', '{"crop_damage":null}', $json(2500), $json(2501)] as $rules) {
        $form = new WorldEvent();
        $form->rules_json = $rules;
        $form->validateRules();
        economyCheck($form->getErrors('rules_json') === EventRules::standard()->errors($rules), 'Yii validation delegates to the shared event registry');
    }
    $db->createCommand()->update('world_node', ['visibility' => 'private'], ['id' => $bedId])->execute();
    try {
        WorldEvent::atNode($bedId, 0)->all();
        throw new RuntimeException('Private node events exposed.');
    } catch (\common\modules\world\support\GameError $error) {
        economyCheck($error->status === 404, 'existing node access check precedes event listing');
    }
} finally {
    $transaction->rollBack();
}
echo 'World event foundation checks passed.' . PHP_EOL;
