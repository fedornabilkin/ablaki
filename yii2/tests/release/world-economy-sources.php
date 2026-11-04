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
$goalsForChecklist = new \common\modules\world\models\domain\WorldGoals($db);
$initialChecklist = array_column($goalsForChecklist->state($freshUser)['checklist_steps'], null, 'code');
economyCheck($initialChecklist['mine_built']['completed'] && !$initialChecklist['mine_resources_mined']['completed'], 'checklist distinguishes a completed mine from actual resource delivery');
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
    if ($code === 'mine-stone') {
        $steps = array_column($goalsForChecklist->state($freshUser)['checklist_steps'], null, 'code');
        economyCheck($steps['mine_resources_mined']['completed'], 'first delivered resource completes the own-mine checklist without requiring coal and ore');
    }
}
economyCheck($completed === 13 && \common\modules\world\models\domain\EconomyQuota::state($db, $freshUser, 'extraction_daily')['remaining'] === 3, 'all thirteen source actions share one unchanged daily quota');
economyReject(function () use ($freshUser, $sourceNodes) { economySourceCommand($freshUser, $sourceNodes['trap'], 'start', ['action_code' => 'make-raw-hide']); }, 'SOURCE_COOLDOWN');
$trapState = \common\modules\world\models\Mining::state($sourceNodes['trap'], $freshUser);
economyCheck($trapState['actions'][0]['available_at'] > $trapState['server_time'], 'trap read model exposes the next server-controlled claim time');
economyReject(function () use ($freshUser, $sourceNodes) { economySourceCommand($freshUser, $sourceNodes['meadow-plot'], 'start', ['action_code' => 'gather-magic-herb']); }, 'SOURCE_DAILY_LIMIT');
$reconcile = $db->beginTransaction();
try {
    $db->createCommand()->update('world_economy_fact', ['quantity' => 0], ['user_id' => $freshUser])->execute();
    $steps = array_column($goalsForChecklist->state($freshUser)['checklist_steps'], null, 'code');
    economyCheck($steps['mine_resources_mined']['completed'] && $steps['mine_built']['completed'], 'historical mine and delivered work restore checklist facts without writing rewards');
    $stranger = array_column($goalsForChecklist->state(9001)['checklist_steps'], null, 'code');
    economyCheck(!$stranger['mine_resources_mined']['completed'], 'another player cannot inherit the own-mine completion');
} finally { $reconcile->rollBack(); }

$workspace = new \common\modules\world\modules\craft\models\domain\CraftWorkspace($db, $flags);
$workspaceCatalog = $workspace->state($freshUser, $siteId, null)['recipes'];
$pickaxeId = (int)(new \yii\db\Query())->select('id')->from('craft_recipe')->where(['code' => 'classic-pickaxe'])->scalar($db);
$pickaxe = array_column($workspaceCatalog, null, 'id')[$pickaxeId];
$ingotId = (int)(new \yii\db\Query())->select('id')->from('craft_item')->where(['code' => 'classic-ingot'])->scalar($db);
$ingot = array_column($pickaxe['materials'], null, 'item_id')[$ingotId];
$requiredIngots = (int)(new \yii\db\Query())->select('item_quantity')->from('craft_recipe_item')->where(['recipe_id' => $pickaxeId, 'item_id' => $ingotId])->scalar($db);
economyCheck($ingot['quantity'] === $requiredIngots && $ingot['recipe_id'] > 0, 'locked pickaxe still exposes exact ingot requirements and a crafting recipe link');
economyCheck(count($pickaxe['locked_reasons']) > 0 && !$pickaxe['available'], 'missing equipment is a lock, not merely a material shortage');
$placement = (new \common\modules\world\modules\craft\models\domain\WorldStorage($db, $flags))->placementOptions($freshUser, $siteId)['items'];
$ingotName = (new \yii\db\Query())->select('name')->from('craft_item')->where(['id' => $ingotId])->scalar($db);
$rawMaterial = array_values(array_filter($placement, static fn($item) => $item['name'] === $ingotName));
economyCheck(count($rawMaterial) > 0 && !$rawMaterial[0]['available'] && $rawMaterial[0]['input'] === null, 'full placement catalog retains incompatible materials without granting a transfer');
$search = new \common\modules\world\models\TemplateSearch();
$allowed = $search->search([], $siteId)->getTotalCount();
$all = (new \common\modules\world\models\TemplateSearch())->search([], $siteId, true)->getTotalCount();
economyCheck($all > $allowed, 'complete construction catalog includes templates for other node types while the default API stays filtered');
echo 'Economy source checks passed.' . PHP_EOL;
