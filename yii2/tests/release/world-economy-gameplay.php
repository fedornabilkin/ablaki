<?php
/** Test-only clock: production services keep their real server clock. */
namespace WorldEconomyFixture {
    final class Clock { public static $at; public static function now(): int { return self::$at ?? \time(); } }
}
namespace common\modules\world\models { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\modules\world\models\domain { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\modules\world\support { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\modules\world\modules\craft\models\domain { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\modules\world\modules\economy\models\domain { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\modules\world\modules\progression\models\domain { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\modules\exchange\service { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace common\services\user { function time() { return \WorldEconomyFixture\Clock::now(); } }
namespace {
require __DIR__ . '/world-economy-sources.php';
use WorldEconomyFixture\Clock;
use common\modules\world\models\{Build, BuildWork, Command, Mining, Node, NodeFactory, NodeTemplate, StarterPack, Supplies};
use common\modules\world\models\domain\{EconomyConstruction, EconomyRuntime, WorldAccessPolicy, WorldCultivation, WorldFlags, WorldGoals, WorldHousing, WorldMapCells, WorldNights};
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage, Crafting, WorldStorage};
use common\modules\world\modules\economy\models\domain\{BudgetFunding, StarterOrders, TreasuryCommands};
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\JobQueue;
use yii\db\Query;

class EconomyGameplayIdentity extends CraftIdentity {
    public $player;
    public function __construct(int $id) { $this->player = $id; }
    public function getId() { return $this->player; }
}
function economyGameplayMoney($db): Money {
    $total = Money::parse('0');
    foreach (['persone' => 'credit', 'economy_account' => 'amount'] as $table => $column) foreach ((new Query())->select($column)->from($table)->column($db) as $amount) $total = $total->add(Money::parse((string)$amount));
    if ($db->schema->getTableSchema('credit_transfer')) foreach ((new Query())->select('amount')->from('credit_transfer')->where(['user_buyer' => 0])->column($db) as $amount) $total = $total->add(Money::parse((string)$amount));
    return $total;
}
final class EconomyGameplay
{
    private $db; private $user; private $city; private $flags; private $store; private $start; private $scenario;
    private $nodes = []; private $orders = []; private $bedCycle;
    public function __construct($db, int $user, int $city, int $start, array $scenario) {
        $this->db = $db; $this->user = $user; $this->city = $city; $this->start = $start; $this->scenario = $scenario;
        $this->flags = new WorldFlags($db, Yii::$app->getModule('world')); $this->store = new CraftStorage($db);
        $this->nodes['settlement'] = $city;
    }
    private function item(string $code): array { return (new Query())->from('craft_item')->where(['code' => $code])->one($this->db); }
    private function run(string $type, array $payload, callable $handler): array { return Command::run($this->user, bin2hex(random_bytes(16)), $type, $payload, $handler); }
    private function advance(int $seconds): void { Clock::$at += $seconds; }
    private function build(string $code, string $parent): void {
        if ($code === 'starter-site') {
            $result = $this->run('world.start', ['city_id' => $this->city], function ($op) { return StarterPack::enter($this->user, $this->city, $op); });
            $project = Build::requireOne($result['build']['id']);
        } else {
            $template = NodeTemplate::requireOne(['code' => $code]);
            $input = ['parent_id' => $this->nodes[$parent], 'template_id' => (int)$template->id, 'name' => $template->name, 'labor_budget' => '0.0000', 'top_up' => true, 'terms_digest' => EconomyConstruction::terms($template)['terms_digest']];
            $result = $this->run('world.build.create', $input, function ($op) use ($input) { return ['id' => (int)Build::begin($this->user, $input, $op)->id]; });
            $project = Build::requireOne($result['id']);
        }
        $this->nodes[$code] = (int)$project->node_id;
        $this->run('world.build.start', ['build_id' => (int)$project->id], function () use ($project) { return BuildWork::start($project, $this->user); });
        for ($ticks = 0; $project->status === 'building' && $ticks < 1000; $ticks++) {
            $this->advance(min(30, max(1, (int)$project->required_seconds - (int)$project->worked_seconds)));
            $this->run('world.build.heartbeat', ['build_id' => (int)$project->id], function ($op) use ($project) { $project->refresh(); return BuildWork::heartbeat($project, $this->user, $op, false); });
            $project->refresh();
        }
        economyCheck($project->status === 'completed', 'gameplay build completes: ' . $code);
        $this->store->workspaceNodeId = $this->nodes['starter-site'];
    }
    public function fund(): void {
        $orders = new StarterOrders($this->db, $this->flags, new WorldAccessPolicy(9001, true));
        foreach ($this->scenario['orders'] as $code => $order) {
            $input = ['node_id' => $this->city, 'item_id' => (int)$this->item($code)['id'], 'quantity' => $order['quantity'], 'per_user_limit' => $order['quantity'], 'lifetime_hours' => 168,
                'unit_price' => $order['unit_price_cr'], 'purpose' => 'Finite gameplay acceptance demand', 'initialize_starter_policy' => true];
            $quote = $orders->preview(9001, $input, 'publish');
            $result = $orders->execute(9001, bin2hex(random_bytes(16)), $input, $quote['quote_id'], (array)$quote['expected_revisions'], 'publish');
            $this->orders[$code] = (int)(new Query())->select('id')->from('economy_purchase_order')->where(['node_id' => $this->city, 'item_id' => $input['item_id']])->orderBy(['id' => SORT_DESC])->scalar($this->db);
        }
        foreach (['day-1', 'day-2'] as $code) Command::run(9001, bin2hex(random_bytes(16)), 'world.goal.fund', ['settlement_id' => $this->city, 'code' => $code], function ($op) use ($code) { return (new WorldGoals($this->db))->fund($this->city, $code, 1, 9001, $op); });
    }
    private function source(string $code, int $quantity): void {
        $definition = EconomyRuntime::current($this->db)['profile']['actions'][$code]; $node = $this->nodes[$definition['node']];
        for ($batch = 0; $batch < $quantity; $batch++) {
            $state = $this->run('world.mining.start', ['mining_node_id' => $node, 'action_code' => $code], function ($op) use ($node, $code) { return Mining::start($node, $this->user, $op, $code); });
            while ($state['working']) {
                $this->advance(min(30, max(1, $state['seconds_per_unit'] - $state['worked_seconds'])));
                $state = $this->run('world.mining.heartbeat', ['mining_node_id' => $node], function ($op) use ($node) { return Mining::heartbeat($node, $this->user, $op); });
            }
            if ($state['pending_quantity']) throw new RuntimeException('Gameplay source blocked: ' . $code . ' ' . $state['blocked_code']);
        }
    }
    private function place(string $code, string $place): void {
        $backpack = (new CanonicalInventory($this->store))->backpack($this->user);
        $inventory = (new Query())->from('craft_inventory')->where(['storage_id' => $backpack['id'], 'item_id' => $this->item($code)['id']])->andWhere(['>', 'item_quantity', 0])->one($this->db);
        $storage = (new Query())->from('craft_storage')->where(['node_id' => $this->nodes[$place], 'kind' => 'placement'])->one($this->db);
        $node = Node::requireOne($this->nodes[$place]);
        $input = ['source_storage_id' => (int)$backpack['id'], 'inventory_id' => (int)$inventory['id'], 'destination_storage_id' => (int)$storage['id'], 'position' => 1 - (int)$node->map_origin_y * (int)$node->map_width - (int)$node->map_origin_x, 'quantity' => 1, 'instance_id' => null];
        $service = new WorldStorage($this->db, $this->flags); $quote = $service->preview($this->user, $input);
        $service->transfer($this->user, bin2hex(random_bytes(16)), $input, $quote['quote_id'], (array)$quote['expected_revisions']);
    }
    private function crop(string $action): void {
        $service = new WorldCultivation($this->db, $this->flags, new WorldAccessPolicy($this->user));
        $bed = $this->nodes['garden-bed'];
        if ($action === 'sow' && !$service->state($this->user, $bed)['dug']) $this->crop('dig');
        $input = ['bed_id' => $bed, 'top_up' => true];
        if ($action === 'sow') $input['crop_revision_id'] = (int)(new Query())->select('r.id')->from(['r' => 'world_crop_revision'])->innerJoin(['c' => 'world_crop'], 'c.id=r.crop_id')->where(['c.code' => 'carrot', 'r.status' => 'published'])->scalar($this->db);
        if ($action === 'water') {
            $cycle = $service->state($this->user, $bed)['cycle']; Clock::$at = max(Clock::$at, $cycle['water_due_at']);
        }
        $quote = $service->preview($this->user, $action, $input);
        $service->execute($this->user, $action, $input, bin2hex(random_bytes(16)), $quote['quote_id'], (array)$quote['expected_revisions']);
    }
    private function nightJobs(): void {
        $nights = new WorldNights($this->db, $this->flags); $queue = new JobQueue($this->db);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $claim = $queue->claim(['world.night.resolve', 'world.night.enroll']); if (!$claim) return;
            if (!$queue->perform($claim, function ($payload, $job) use ($nights) { if ($job['type'] === 'world.night.enroll') $nights->enroll($payload); else $nights->resolve($payload, $job); })) throw new RuntimeException('Night job failed.');
        }
        throw new RuntimeException('Night job fixture did not settle.');
    }
    public function steps(array $steps): void {
        foreach ($steps as $number => $step) {
            $action = $step['action']; $code = $step['code'] ?? ''; $quantity = $step['quantity'] ?? 1;
            echo 'GAMEPLAY ' . $number . ' ' . $action . ' ' . $code . ' at ' . date('c', Clock::$at) . PHP_EOL;
            if ($action === 'build') $this->build($code, $step['parent']);
            elseif ($action === 'craft') {
                $recipe = (int)(new Query())->select('id')->from('craft_recipe')->where(['code' => $code])->scalar($this->db);
                (new Crafting($this->store))->command($this->user, bin2hex(random_bytes(16)), 'craft', ['id' => $recipe, 'quantity' => $quantity]);
            } elseif ($action === 'source') $this->source($code, $quantity);
            elseif ($action === 'place') $this->place($code, $step['node']);
            elseif (in_array($action, ['sow', 'water', 'harvest'], true)) $this->crop($action);
            elseif ($action === 'daily') $this->run('world.supplies.gather', ['node_id' => $this->nodes['starter-site']], function ($op) { return Supplies::gather($this->nodes['starter-site'], $this->user, $op); });
            elseif ($action === 'goal') $this->run('world.goal.claim', ['code' => $code], function ($op) use ($code) { return (new WorldGoals($this->db))->claim($this->user, $code, $op); });
            elseif ($action === 'day') { Clock::$at = $this->start + ($quantity - 1) * 86400; $this->nightJobs(); }
            elseif ($action === 'lodge') { $service = new WorldHousing($this->db, $this->flags); $input = ['node_id' => $this->nodes[$code]]; $q = $service->preview($this->user, $input, 'lodge'); $service->execute($this->user, bin2hex(random_bytes(16)), $input, $q['quote_id'], (array)$q['expected_revisions'], 'lodge'); }
            elseif ($action === 'explore' || $action === 'buy') {
                $service = new WorldMapCells($this->db, $this->flags, new WorldAccessPolicy($this->user));
                $input = $service->input($this->nodes[$code], ['cells' => array_map(function ($cell) { return ['x' => $cell[0], 'y' => $cell[1]]; }, $step['cells']), 'top_up' => true, 'use_elixir' => $step['use_elixir'] ?? false]);
                $q = $service->preview($this->user, $input, $action); $service->execute($this->user, bin2hex(random_bytes(16)), $input, $q['quote_id'], (array)$q['expected_revisions'], $action);
            } elseif ($action === 'grant') {
                $service = new \common\modules\world\modules\economy\models\domain\BudgetGrants($this->db, $this->flags);
                $input = ['source_node_id' => $this->nodes[$step['source']], 'destination_node_id' => $this->nodes[$step['destination']], 'amount' => $step['amount_cr'], 'purpose' => 'Own infrastructure development'];
                $q = $service->preview($this->user, $input); $service->execute($this->user, bin2hex(random_bytes(16)), $input, $q['quote_id'], (array)$q['expected_revisions']);
            } elseif ($action === 'collect') {
                $service = new TreasuryCommands($this->db, $this->flags); $input = ['node_id' => $this->nodes[$code]];
                $q = $service->preview($this->user, $input, false); $service->execute($this->user, bin2hex(random_bytes(16)), $input, $q['quote_id'], (array)$q['expected_revisions'], false);
            } elseif ($action === 'deliver') {
                $inventory = (new Query())->from('craft_inventory')->where(['user_id' => $this->user, 'item_id' => $this->item($code)['id']])->andWhere(['>=', 'item_quantity', $quantity])->orderBy('id')->one($this->db);
                $service = new StarterOrders($this->db, $this->flags, new WorldAccessPolicy($this->user));
                $input = ['node_id' => $this->city, 'order_id' => $this->orders[$code], 'inventory_id' => (int)$inventory['id'], 'quantity' => $quantity, 'income_node_id' => $this->nodes[$step['destination']]];
                $q = $service->preview($this->user, $input, 'deliver'); $service->execute($this->user, bin2hex(random_bytes(16)), $input, $q['quote_id'], (array)$q['expected_revisions'], 'deliver');
            } elseif ($action !== 'checkpoint') throw new RuntimeException('Unknown gameplay action.');
            $this->advance(1);
        }
    }
    public function summary(): array {
        $accounts = (new Query())->select(['n.code', 'a.role', 'a.amount', 'a.reserved'])->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.id' => array_values($this->nodes)])->orderBy(['n.id' => SORT_ASC, 'a.role' => SORT_ASC])->all($this->db);
        return ['wallet_cr' => (string)(new Query())->select('credit')->from('persone')->where(['user_id' => $this->user])->scalar($this->db), 'accounts' => $accounts,
            'crafted_recipes' => (int)(new Query())->select('recipe_id')->distinct()->from('craft_event')->where(['user_id' => $this->user, 'action' => 'craft'])->count('*', $this->db),
            'skill_xp' => (int)(new Query())->from('craft_skill')->where(['user_id' => $this->user])->sum('experience', $this->db)];
    }
}

// A separate world and finite donor balance are fixture inputs, never production installation.
$scenario = json_decode(file_get_contents($yiiRoot . '/common/modules/world/data/economy/closed-test-scenarios-v1.json'), true, 64, JSON_THROW_ON_ERROR);
$start = strtotime('2026-10-19T10:00:00+03:00'); Clock::$at = $start - 14400;
$db->transaction(function () use ($db, &$gameCity, &$gameWorld) {
    $world = NodeFactory::create(NodeTemplate::requireOne(['code' => 'world']), null, null, 'Gameplay fixture', 'gameplay-world');
    $region = NodeFactory::create(NodeTemplate::requireOne(['code' => 'region']), $world, null, 'Gameplay region', 'gameplay-region');
    $city = NodeFactory::create(NodeTemplate::requireOne(['code' => 'city']), $region, null, 'Gameplay city', 'gameplay-city');
    $gameWorld = (int)$world->id; $gameCity = (int)$city->id;
    $db->createCommand()->update('world_registry', ['active_world_id' => $gameWorld], ['id' => 1])->execute();
});
$db->createCommand()->delete('game_job')->execute();
$nightRules = ['day_seconds' => 86400, 'night_offset' => 57600, 'night_seconds' => 28800, 'max_severity' => 2, 'recovery_nights' => 2, 'mild_efficiency_bps' => 7500, 'severe_efficiency_bps' => 5000];
(new WorldNights($db, new WorldFlags($db, Yii::$app->getModule('world'))))->activate($gameWorld, $nightRules);
Clock::$at = $start;
foreach ([9005, 9006] as $id) {
    $db->createCommand()->insert('user', ['id' => $id, 'username' => 'Gameplay' . $id])->execute();
    $db->createCommand()->insert('persone', ['id' => $id, 'user_id' => $id, 'credit' => $id === 9006 ? '1000.0000' : '0.0000', 'balance' => '0.0000'])->execute();
    $app->authManager->assign($testRole, $id);
}
if (!$db->schema->getTableSchema('persone')->getColumn('rating')) $db->createCommand()->addColumn('persone', 'rating', 'decimal(18,5) NOT NULL DEFAULT 0')->execute();
if (!$db->schema->getTableSchema('credit_transfer')) $db->createCommand()->createTable('credit_transfer', ['id' => 'pk', 'user_id' => 'integer', 'user_buyer' => 'integer NOT NULL DEFAULT 0', 'amount' => 'integer', 'password' => 'string', 'created_at' => 'integer', 'updated_at' => 'integer'])->execute();
$db->schema->refresh();
// Match the deployed legacy PostgreSQL CHAR columns, including padded PDO values.
if ($db->driverName === 'pgsql') {
    $db->createCommand('ALTER TABLE history_balance ALTER COLUMN type TYPE CHAR(50)')->execute();
    $db->createCommand('ALTER TABLE history_balance ALTER COLUMN comment TYPE CHAR(255)')->execute();
    $db->schema->refresh();
}
$initialMoney = economyGameplayMoney($db);
$app->user->switchIdentity(new EconomyGameplayIdentity(9006));
$transferService = new \common\modules\exchange\service\TransferService(); $transfer = new \common\modules\exchange\models\CreditTransfer(); $transfer->amount = 250; $transferService->create($transfer);
$row = (new Query())->from('credit_transfer')->where(['user_id' => 9006, 'user_buyer' => 0])->one($db);
$app->user->switchIdentity(new EconomyGameplayIdentity(9005)); $transferService->confirm(\common\modules\exchange\models\CreditTransfer::findOne($row['id']), $row['password']); unset($row);
economyCheck(Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => 9005])->scalar($db))->decimal() === '250.0000', 'gameplay initial credits arrive through the existing transfer');
$journal = new \common\modules\world\models\domain\EconomyHistory($db);
$received = $journal->listing(9005, 'transfers', 1, '')['items'][0];
$sent = $journal->listing(9006, 'transfers', 1, '')['items'][0];
economyCheck($sent['transfer_ids'] === $received['transfer_ids'] && count($sent['transfer_ids']) === 1
    && $sent['transfer_phase'] === 'create' && $received['transfer_phase'] === 'claim'
    && $received['balance_before_cr'] === '0.0000' && $received['kind'] === 'transfer' && $received['classification'] === 'internal_transfer', 'existing transfer journal normalizes legacy CHAR fields and correlates both parties without exposing its password');
$app->user->switchIdentity(new EconomyGameplayIdentity(9006));
$cancelFixture = new \common\modules\exchange\models\CreditTransfer(); $cancelFixture->amount = 1;
$transferService->create($cancelFixture);
$cancelId = (int)(new Query())->select('id')->from('credit_transfer')->where(['user_id' => 9006, 'user_buyer' => 0])->scalar($db);
$transferService->delete(\common\modules\exchange\models\CreditTransfer::findOne($cancelId));
$cancelled = $journal->listing(9006, 'transfers', 1, '')['items'][0];
economyCheck($cancelled['transfer_phase'] === 'cancel' && $cancelled['transfer_ids'] === [$cancelId]
    && !(new Query())->from('credit_transfer')->where(['id' => $cancelId])->exists($db), 'cancelled transfer ID survives deletion in the existing balance history');
$beforeFailure = economyGameplayMoney($db)->decimal();
$transferCount = (int)(new Query())->from('credit_transfer')->count('*', $db);
$balanceCount = (int)(new Query())->from('history_balance')->count('*', $db);
$db->createCommand()->createIndex('fixture_one_transfer_per_history', 'credit_transfer_history', 'history_id', true)->execute();
$failedBatch = new \common\modules\exchange\api\models\CreditTransfer(); $failedBatch->amount = 1; $failedBatch->count = 2;
try {
    try { $transferService->create($failedBatch); throw new RuntimeException('Expected transfer audit failure.'); }
    catch (\yii\db\Exception $expectedFailure) {}
} finally { $db->createCommand()->dropIndex('fixture_one_transfer_per_history', 'credit_transfer_history')->execute(); $db->schema->refresh(); }
economyCheck(economyGameplayMoney($db)->decimal() === $beforeFailure
    && (int)(new Query())->from('credit_transfer')->count('*', $db) === $transferCount
    && (int)(new Query())->from('history_balance')->count('*', $db) === $balanceCount, 'failed transfer correlation rolls back funds, transfer and balance history together');
Command::run(9006, bin2hex(random_bytes(16)), 'fixture.municipal-funding', [], function ($op) use ($db, $gameCity) { return (new BudgetFunding($db))->contribute(9006, $gameCity, Money::parse('560.0000'), 'Finite fixture budget', $op); });
$gameplay = new EconomyGameplay($db, 9005, $gameCity, $start, $scenario); $gameplay->fund();
$scenarioName = getenv('ABL_ECONOMY_SCENARIO') ?: 'first-seven-days-with-lodging';
if (!in_array($scenarioName, ['first-five-days-with-lodging', 'first-seven-days-with-lodging'], true)) throw new RuntimeException('Unknown acceptance route.');
$gameplay->steps(array_merge($scenario['common_steps'], $scenario['scenarios'][$scenarioName]));
economyCheck((new Query())->from('world_goal_claim')->where(['world_id' => $gameWorld, 'user_id' => 9005, 'goal_code' => 'day-2'])->exists($db), 'actual command route reaches the second funded milestone on day five');
$actor = (int)(new Query())->select('id')->from('game_actor')->where(['user_id' => 9005])->scalar($db);
economyCheck((int)(new Query())->from('world_night_resolution')->where(['actor_id' => $actor, 'outcome' => 'protected'])->count('*', $db) === ($scenarioName === 'first-seven-days-with-lodging' ? 5 : 3)
    && !(new Query())->from('world_night_resolution')->where(['actor_id' => $actor, 'outcome' => 'unprotected'])->exists($db), 'the assigned bed protects every eligible night in worker processing');
$craftSink = Money::parse('0');
foreach ((new Query())->select('credit_up')->from('history_balance')->where(['user_id' => 9005, 'type' => 'craft'])->column($db) as $delta) $craftSink = $craftSink->subtract(Money::parse((string)$delta));
economyCheck(economyGameplayMoney($db)->add($craftSink)->compare($initialMoney) === 0, 'wallets, existing transfer reserves and all accounts reconcile exactly after the full route, including explicit craft fees');
economyCheck(!(new Query())->from('economy_account')->where(['or', ['<', 'amount', 0], ['<', 'reserved', 0], new \yii\db\Expression('reserved > amount')])->exists($db), 'full route has no negative or over-reserved accounts');
$beforeReport = economyGameplayMoney($db)->decimal();
$observation = (new \common\modules\world\models\domain\EconomyReport($db))->report('', 9004, 1);
$observed = $observation['users'][0];
economyCheck($observed['user_id'] === 9005 && $observed['finances']['earned_cr'] === ($scenarioName === 'first-seven-days-with-lodging' ? '380.0000' : '332.0000')
    && $observed['finances']['rewards_cr'] === '150.0000', 'observation report separates earned sales and funded rewards from personal starting funds');
economyCheck(isset($observed['retention']['D30']) && economyGameplayMoney($db)->decimal() === $beforeReport
    && strpos(json_encode($observation), 'password') === false, 'D30 is instrumented and operational report neither mutates money nor exposes transfer secrets');
echo 'GAMEPLAY SUMMARY ' . json_encode($gameplay->summary(), JSON_THROW_ON_ERROR) . PHP_EOL;
if ($scenarioName === 'first-seven-days-with-lodging') {
    $types = (new Query())->select('t.code')->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')->where(['n.owner_user_id' => 9005, 'n.status' => 'active', 'n.root_id' => $gameWorld])->column($db);
    economyCheck(in_array('workshop-building', $types, true), 'day seven includes a completed early workshop');
    economyCheck(in_array('storage-building', $types, true) && in_array('house', $types, true), 'day seven completes medium storage and house using the adjusted candidate profile');
    $mediumStorage = (new Query())->from(['s' => 'craft_storage'])->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
        ->innerJoin(['t' => 'world_template'], 't.id=n.template_id')->where(['s.owner_user_id' => 9005, 's.kind' => 'stockpile', 's.capacity' => 50, 't.code' => 'storage-building'])->exists($db);
    economyCheck($mediumStorage, 'completed medium storage provisions fifty canonical inventory slots');
    $stock = (new CraftStorage($db))->quantities(9005);
    $iron = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-pickaxe'])->scalar($db);
    economyCheck(($stock[$iron] ?? 0) === 1 && $gameplay->summary()['crafted_recipes'] >= 15, 'week route crafts an iron pickaxe and at least fifteen distinct recipes without additional transfers');
    economyCheck((new Query())->from('craft_storage')->where(['owner_user_id' => 9005, 'kind' => 'chest', 'capacity' => 20])->exists($db), 'week route places a twenty-slot chest without destroying existing contents');
}
// With all remaining funded demand removed, no hidden buyer or daily Cr appears.
$remainingOrder = (new Query())->from('economy_purchase_order')->where(['node_id' => $gameCity, 'status' => 'open'])->one($db);
$closedMoney = economyGameplayMoney($db)->decimal();
$orderService = new StarterOrders($db, new WorldFlags($db, Yii::$app->getModule('world')), new WorldAccessPolicy(9001, true));
$closeInput = ['node_id' => $gameCity, 'order_id' => (int)$remainingOrder['id']];
$closeQuote = $orderService->preview(9001, $closeInput, 'cancel');
$orderService->execute(9001, bin2hex(random_bytes(16)), $closeInput, $closeQuote['quote_id'], (array)$closeQuote['expected_revisions'], 'cancel');
economyCheck(!(new Query())->from('economy_purchase_order')->where(['node_id' => $gameCity, 'status' => 'open'])->exists($db)
    && economyGameplayMoney($db)->decimal() === $closedMoney, 'zero funded demand releases only the unused reserve and does not issue credits');
economyReject(function () use ($db, $gameCity, $remainingOrder) {
    $orders = new StarterOrders($db, new WorldFlags($db, Yii::$app->getModule('world')), new WorldAccessPolicy(9005));
    $orders->preview(9005, ['node_id' => $gameCity, 'order_id' => (int)$remainingOrder['id'], 'inventory_id' => 1, 'quantity' => 1], 'deliver');
}, 'ORDER_CLOSED');
echo 'Economy gameplay checks passed.' . PHP_EOL;
}
