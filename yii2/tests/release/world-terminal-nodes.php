<?php
/** Disposable fixtures: terminal structure, inherited finance and legacy money migration. */
require __DIR__ . '/world-cohort-presence.php';

use common\modules\world\models\{Node, NodeFactory, NodeTemplate, TemplateSearch};
use common\modules\world\models\domain\{CommonNodePolicy, PlacementProvisioner, WorldAccessPolicy, WorldQuery};
use common\modules\world\modules\economy\models\Account;
use common\modules\world\modules\economy\models\domain\{BudgetSpending, EconomyHierarchy, LeafFinanceMigration, NodeEconomy};
use common\modules\world\modules\economy\value\Money;
use yii\db\Query;

$tx = $db->beginTransaction();
try {
    $parent = Node::requireOne($siteId);
    $template = NodeTemplate::requireOne(['code' => 'mini-storage-level-5']);
    $leaf = NodeFactory::create($template, $parent, $freshUser, 'Terminal fixture', 'terminal-fixture', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $leaf->max_condition], ['id' => $leaf->id])->execute(); $leaf->refresh();
    $id = (int)$leaf->id; $hierarchy = new EconomyHierarchy($db);
    $dto = (new WorldQuery($db, new WorldAccessPolicy($freshUser)))->node($id);
    economyCheck(!$dto['has_finances'] && !$dto['can_contain_nodes'] && !$dto['treasury_enabled'], 'terminal DTO has no finances, treasury or child-object capability');
    economyCheck($dto['permissions']['storage'] && !(new CommonNodePolicy())->permitsChild($leaf->getAttributes()), 'terminal objects retain item ownership but reject object nesting');
    economyCheck((new TemplateSearch())->search([], $id)->getTotalCount() === 0, 'terminal build catalogue is empty');
    // Even an administrator-defined edge cannot bypass the terminal parent policy.
    $db->createCommand()->insert('world_template_parent', ['template_id' => $template->id, 'parent_template_id' => $template->id])->execute();
    economyReject(static fn() => NodeFactory::create($template, $leaf, $freshUser, 'Forbidden', 'terminal-forbidden', null, 'constructing'), 'VALIDATION_FAILED');
    economyCheck($hierarchy->financialNode($id) === $siteId, 'terminal payments resolve to the nearest composite parent');
    economyReject(static fn() => Account::state($id, $freshUser), 'PARENT_BUDGET_REQUIRED');
    economyReject(static fn() => (new NodeEconomy($db, $flags))->obligations($freshUser, $id, 1), 'PARENT_BUDGET_REQUIRED');
    $view = (new NodeEconomy($db, $flags))->view($freshUser, $id);
    economyCheck($view['balances'] === null && !$view['can_invest'] && !$view['can_grant'] && !$view['can_pay'], 'terminal finance reads expose no accounts or financial commands');
    $operation = bin2hex(random_bytes(16));
    $db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $freshUser, 'type' => 'fixture.terminal', 'created_at' => time()])->execute();
    $accounts = $hierarchy->provision($id, $operation);
    economyCheck(!(new Query())->from('economy_subject')->where(['node_id' => $id])->exists($db), 'provisioning a terminal object never creates its own financial subject');
    $placement = (new PlacementProvisioner($db))->interior($leaf);
    (new PlacementProvisioner($db))->openObjectMap($leaf);
    $storage = new \common\modules\world\modules\craft\models\domain\WorldStorage($db, $flags);
    economyCheck(in_array($placement, array_column($storage->list($freshUser, $id)['items'], 'id'), true), 'terminal placement storage remains accessible');
    economyCheck(count($storage->placementOptions($freshUser, $id)['items']) > 0, 'terminal object still offers compatible items for placement');
    $bedItem = (new Query())->from('craft_item')->where(['code' => 'classic-bed'])->one($db);
    $craftStore = new \common\modules\world\modules\craft\models\domain\CraftStorage($db); $craftStore->operationId = $operation;
    $craftStore->move($freshUser, $bedItem, 1);
    $options = $storage->placementOptions($freshUser, $id, ['x' => 0, 'y' => 0]);
    $choice = array_values(array_filter($options['items'], static fn($item) => $item['name'] === $bedItem['name'] && $item['available']))[0];
    $quote = $storage->preview($freshUser, $choice['input']); $key = bin2hex(random_bytes(16));
    $placed = $storage->transfer($freshUser, $key, $choice['input'], $quote['quote_id'], (array)$quote['expected_revisions']);
    economyCheck($storage->transfer($freshUser, $key, $choice['input'], $quote['quote_id'], (array)$quote['expected_revisions']) === $placed
        && (new Query())->from('craft_inventory')->where(['storage_id' => $placement, 'item_id' => $bedItem['id'], 'item_quantity' => 1])->exists($db),
        'a compatible item is placed in the terminal object exactly once');

    // Reproduce real pre-change accounts and a live commitment, not a contribution through new routing.
    $db->createCommand()->insert('economy_subject', ['node_id' => $id, 'created_at' => time()])->execute(); $subject = (int)$db->getLastInsertID();
    $old = [];
    foreach (['budget' => '11.0000', 'treasury' => '7.0000'] as $role => $amount) {
        $db->createCommand()->insert('economy_account', ['subject_id' => $subject, 'role' => $role, 'currency' => 'Cr', 'amount' => $amount, 'reserved' => '0.0000'])->execute();
        $old[$role] = (int)$db->getLastInsertID();
    }
    $spending = new BudgetSpending($db); $hold = $spending->reserve($old['budget'], Money::parse('3'), 'Legacy held work', $operation);
    $total = $npcTotal(); $before = $hierarchy->accounts($siteId);
    (new LeafFinanceMigration($db))->run('terminal-fixture');
    $after = $hierarchy->accounts($siteId);
    foreach (['budget' => '11', 'treasury' => '7'] as $role => $amount) {
        economyCheck(Money::parse((string)$after[$role]['amount'])->compare(Money::parse((string)$before[$role]['amount'])->add(Money::parse($amount))) === 0, 'legacy terminal ' . $role . ' moves intact to the parent');
        economyCheck((new Query())->from('economy_account')->where(['id' => $old[$role], 'amount' => 0, 'reserved' => 0])->exists($db), 'retired terminal account is empty');
    }
    economyCheck((int)$spending->commitment($hold)['account_id'] === (int)$after['budget']['id'] && $npcTotal() === $total, 'migration preserves money and rebinds the live reserved commitment');
    $journal = (new Query())->from('economy_transfer')->orderBy('id')->all($db);
    (new LeafFinanceMigration($db))->run('terminal-fixture');
    economyCheck($hierarchy->accounts($siteId) === $after && (new Query())->from('economy_transfer')->orderBy('id')->all($db) === $journal, 'repeating migration does not transfer twice or rewrite history');
    $spending->release($hold);
    economyCheck(Money::parse((string)$hierarchy->accounts($siteId)['budget']['reserved'])->compare(Money::parse((string)$before['budget']['reserved'])) === 0, 'accepted work can release its migrated reserve from the parent');
} finally { $tx->rollBack(); }
echo "World terminal-node checks passed.\n";
