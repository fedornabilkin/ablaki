<?php
/** New balance on the disposable fixture, following the published-terms regressions. */
require __DIR__ . '/world-feedback-food.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . PHP_EOL); exit(1); });

use common\modules\world\models\{Command, Node, NodeFactory, NodeTemplate};
use common\modules\world\models\domain\{EconomyProfile, EconomyProfilePublisher, EconomyQuota, EconomyRuntime, FurnishingPlacement, HousingBenefits, NpcInfrastructure, NpcMaintenance, PlacementProvisioner, WorldFlags, WorldHousing};
use common\modules\world\modules\economy\models\domain\{EconomyHierarchy, TreasuryLedger};
use common\modules\world\modules\economy\value\Money;
use yii\db\Query;

$candidate = EconomyProfile::load();
EconomyProfile::validate($candidate);
economyCheck($candidate['objects']['drilling-rig']['price_cr'] === '650.0000' && $candidate['objects']['drilling-rig']['work_seconds'] === 3600, 'rig remains a funded premium goal with one hour of active construction');
economyCheck($candidate['objects']['house']['price_cr'] === '180.0000' && $candidate['objects']['house']['work_seconds'] === 600, 'house is a reachable premium upgrade above the twenty-credit shelter');
foreach ([0, 21, 2.5, '5'] as $bonus) {
    $invalid = $candidate; $invalid['housing_benefits']['house']['extraction_daily_bonus'] = $bonus;
    try { EconomyProfile::validate($invalid); throw new RuntimeException('Invalid bonus accepted'); }
    catch (InvalidArgumentException $e) { economyCheck(true, 'house bonus rejects invalid quantities'); }
}
foreach ([['reward_cr', '0.0000'], ['target_budget_cr', '2.0000'], ['bot_user_id', 9001], ['settle_public_obligations', 1]] as [$field, $value]) {
    $invalid = $candidate; $invalid['npc'][$field] = $value;
    try { EconomyProfile::validate($invalid); throw new RuntimeException('Invalid NPC policy accepted'); }
    catch (InvalidArgumentException $e) { economyCheck(true, 'NPC profile rejects invalid amounts and identity overrides'); }
}
$publisher = new EconomyProfilePublisher($db);
$beforePublication = $npcTotal();
$quote = $publisher->preview($candidate);
$publisher->publish($candidate, $quote['digest'], 9001, 'New balance acceptance');
economyCheck($npcTotal() === $beforePublication, 'publishing the new prices and benefits does not create money');
$policy = (new NpcInfrastructure($db))->policy();
economyCheck($policy['reward_cr'] === '3.0000' && $policy['target_budget_cr'] === '90.0000' && $policy['idle_budget_cr'] === '0.0000', 'NPC wages and buffers come from the published profile');
economyCheck(\common\modules\world\models\domain\HiredExtraction::rates(Node::requireOne(['code' => 'npc-bot-mine']))['reward_cr'] === '6.0000', 'NPC drilling work pays six existing credits per batch');

$tx = $db->beginTransaction();
try {
    // A separate finite fixture wallet; subsequent operations must conserve it exactly.
    $db->createCommand()->update('persone', ['credit' => '1000.0000'], ['user_id' => $npcUser])->execute();
    $before = $npcTotal(); $private = $npcPrivate();
    $protected = (new Query())->from('economy_spending_commitment')->orderBy('id')->all($db);
    Command::run(9001, bin2hex(random_bytes(16)), 'fixture.balance.circulation', [], static function ($operation) use ($db, $policy, $npcUser) {
        (new TreasuryLedger($db))->receivePersonalPayment($npcUser, (int)$policy['city_node_id'], Money::parse('100'), $operation, 'Finite public receipt');
        (new NpcMaintenance($db))->refresh($policy, $operation);
        return [];
    });
    $pending = (new Query())->from(['o' => 'economy_obligation'])->innerJoin(['a' => 'economy_account'], 'a.id=o.budget_account_id')
        ->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
        ->where(['n.owner_user_id' => $npcUser, 'n.root_id' => $policy['world_id'], 'n.node_type' => ['WORLD','REGION','SETTLEMENT'], 'o.status' => 'pending'])->count('*', $db);
    economyCheck((int)$pending === 0 && $npcTotal() === $before && $npcPrivate() === $private, 'public taxes return to circulation without money issuance or private withdrawals');
    economyCheck($protected === (new Query())->from('economy_spending_commitment')->orderBy('id')->all($db), 'non-tax protected reserves survive NPC maintenance unchanged');
} finally { $tx->rollBack(); }

$tx = $db->beginTransaction();
try {
    $fixtures = Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.balance.homes', [], static function ($operation) use ($db, $freshUser, $siteId) {
        $ids = [];
        foreach (['shelter' => 'starter-shelter', 'house-a' => 'house', 'house-b' => 'house'] as $key => $code) {
            $node = NodeFactory::create(NodeTemplate::requireOne(['code' => $code]), Node::requireOne($siteId), $freshUser, $key, 'balance-' . $key, null, 'constructing');
            $db->createCommand()->update('world_node', ['status' => 'active', 'operational_status' => 'active', 'condition' => $node->max_condition], ['id' => $node->id])->execute(); $node->refresh();
            $storage = (new PlacementProvisioner($db))->interior($node);
            $db->createCommand()->insert('world_build', ['node_id' => $node->id, 'parent_id' => $siteId, 'owner_user_id' => $freshUser, 'required_seconds' => 60, 'worked_seconds' => 60, 'labor_budget' => '0.0000', 'materials_json' => '{}', 'status' => 'completed', 'revision' => 1, 'created_at' => time(), 'finished_at' => time()])->execute();
            $bed = (new Query())->select('id')->from('craft_item')->where(['code' => 'classic-bed'])->scalar($db);
            $db->createCommand()->insert('craft_inventory', ['user_id' => $freshUser, 'item_id' => $bed, 'item_quantity' => 1, 'storage_id' => $storage, 'slot' => 1, 'revision' => 1])->execute();
            FurnishingPlacement::synchronize($db, [$node->id], $operation);
            $ids[$key] = (int)$node->id;
        }
        return $ids;
    });
    $housing = new WorldHousing($db, new WorldFlags($db, $app->getModule('world')));
    $lodge = static function (string $key) use ($housing, $freshUser, $fixtures) {
        $input = ['node_id' => $fixtures[$key], 'replace_current' => true];
        $quote = $housing->preview($freshUser, $input, 'lodge');
        $housing->execute($freshUser, bin2hex(random_bytes(16)), $input, $quote['quote_id'], (array)$quote['expected_revisions'], 'lodge');
    };
    $lodge('shelter');
    economyCheck(EconomyQuota::state($db, $freshUser, 'extraction_daily')['maximum'] === 20, 'bed in a shelter does not grant the premium home bonus');
    $lodge('house-a');
    $quota = EconomyQuota::state($db, $freshUser, 'extraction_daily');
    economyCheck($quota['maximum'] === 25, 'one occupied house grants five extra daily batches');
    $consumeKey = bin2hex(random_bytes(16));
    $consume = static function ($operation) use ($db, $freshUser, $quota) {
        if ($quota['remaining']) EconomyQuota::consume($db, $freshUser, 'extraction_daily', $quota['remaining'], $operation);
        return EconomyQuota::state($db, $freshUser, 'extraction_daily');
    };
    $quota = Command::run($freshUser, $consumeKey, 'fixture.balance.quota', [], $consume);
    economyCheck($quota['remaining'] === 0 && Command::run($freshUser, $consumeKey, 'fixture.balance.quota', [], $consume) === $quota, 'premium batches are consumed once and obey the shared daily limit');
    $quota = EconomyQuota::state($db, $freshUser, 'extraction_daily');
    $lodge('house-b');
    economyCheck(EconomyQuota::state($db, $freshUser, 'extraction_daily') === $quota, 'a second house cannot stack bonuses or reset used batches');
    $lodge('shelter');
    $withoutBonus = EconomyQuota::state($db, $freshUser, 'extraction_daily');
    economyCheck($withoutBonus['maximum'] === 20 && $withoutBonus['used'] === 25 && $withoutBonus['remaining'] === 0, 'leaving the house cannot refund already-used premium batches');
    $lodge('house-b');
    economyCheck(EconomyQuota::state($db, $freshUser, 'extraction_daily')['remaining'] === 0, 'returning home on the same day does not restore quota');
    $db->createCommand()->update('world_node', ['condition' => 0], ['id' => $fixtures['house-b']])->execute();
    economyCheck(HousingBenefits::extractionBonus($db, $freshUser, $candidate) === 0, 'a broken occupied house loses its bonus');
    $db->createCommand()->update('world_node', ['condition' => 100, 'owner_user_id' => 9002], ['id' => $fixtures['house-b']])->execute();
    economyCheck(HousingBenefits::extractionBonus($db, $freshUser, $candidate) === 0, 'foreign housing cannot grant the ownership bonus');
    $db->createCommand()->update('world_node', ['owner_user_id' => $freshUser], ['id' => $fixtures['house-b']])->execute();
    $storage = (new Query())->select('id')->from('craft_storage')->where(['node_id' => $fixtures['house-b'], 'kind' => 'placement'])->scalar($db);
    $db->createCommand()->delete('craft_inventory', ['storage_id' => $storage])->execute();
    economyCheck(HousingBenefits::extractionBonus($db, $freshUser, $candidate) === 0, 'an empty house cannot retain the bed benefit');
} finally { $tx->rollBack(); }
echo "World balance checks passed.\n";
