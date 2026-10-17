<?php
require __DIR__ . '/world-economy-runtime.php';

// Only fixture preparation bypasses construction. Every resource is delivered by Mining commands.
$sourceNodes = ['forest-plot' => $forestId];
$db->transaction(function () use ($db, $freshUser, $siteId, $freshStock, &$sourceNodes) {
    foreach (['stone-plot', 'clay-plot', 'sand-plot', 'meadow-plot', 'water-source', 'mine', 'trap'] as $code) {
        $node = \common\modules\world\models\NodeFactory::create(\common\modules\world\models\NodeTemplate::requireOne(['code' => $code]),
            \common\modules\world\models\Node::requireOne($siteId), $freshUser, 'Source fixture: ' . $code, 'source-fixture-' . $code, null, 'constructing');
        $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $node->max_condition], ['id' => $node->id])->execute();
        $sourceNodes[$code] = (int)$node->id;
    }
    foreach (['world-stone-pickaxe', 'classic-pickaxe', 'world-shovel'] as $code) {
        $item = (new \yii\db\Query())->from('craft_item')->where(['code' => $code])->one($db);
        $freshStock->move($freshUser, $item, 1);
    }
});
$definitions = \common\modules\world\models\domain\EconomyRuntime::current($db)['profile']['actions'];
$completed = 0;
foreach ($definitions as $code => $definition) {
    if ($definition['quota'] !== 'extraction_daily') continue;
    $node = $sourceNodes[$definition['node']]; $before = $freshStock->quantities($freshUser);
    $result = economySourceCommand($freshUser, $node, 'start', ['action_code' => $code]);
    // A zero-work trap still needs a heartbeat to settle its idempotent claim.
    for ($tick = 0; $tick <= (int)ceil($definition['work_seconds'] / 60) && $result['action_code'] !== null; $tick++) $result = economySourceTick($freshUser, $node);
    economyCheck($result['action_code'] === null && $result['pending_quantity'] === 0, 'source command completes and delivers: ' . $code);
    $after = $freshStock->quantities($freshUser);
    foreach ($definition['output'] as $itemCode => $quantity) {
        $itemId = (int)(new \yii\db\Query())->select('id')->from('craft_item')->where(['code' => $itemCode])->scalar($db);
        economyCheck(($after[$itemId] ?? 0) - ($before[$itemId] ?? 0) === $quantity, 'exact source output: ' . $code . '/' . $itemCode);
    }
    $completed++;
}
economyCheck($completed === 13 && \common\modules\world\models\domain\EconomyQuota::state($db, $freshUser, 'extraction_daily')['remaining'] === 3, 'all thirteen source actions share one unchanged daily quota');
economyReject(function () use ($freshUser, $sourceNodes) { economySourceCommand($freshUser, $sourceNodes['trap'], 'start', ['action_code' => 'make-raw-hide']); }, 'SOURCE_COOLDOWN');
$trapState = \common\modules\world\models\Mining::state($sourceNodes['trap'], $freshUser);
economyCheck($trapState['actions'][0]['available_at'] > $trapState['server_time'], 'trap read model exposes the next server-controlled claim time');
economyReject(function () use ($freshUser, $sourceNodes) { economySourceCommand($freshUser, $sourceNodes['meadow-plot'], 'start', ['action_code' => 'gather-magic-herb']); }, 'SOURCE_DAILY_LIMIT');
echo 'Economy source checks passed.' . PHP_EOL;
