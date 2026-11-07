<?php
/** All setup is confined to the disposable release fixture. */
if (($argv[1] ?? '') === 'market-worker') {
    $dsn = getenv('ABL_TEST_DSN');
    if (!is_string($dsn) || !preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432|33316);dbname=ablakin_ci$/D', $dsn)) throw new RuntimeException('Isolated market database required.');
    define('YII_ENABLE_ERROR_HANDLER', false); $root = dirname(__DIR__, 2);
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php'; require __DIR__ . '/worker-barrier.php';
    Yii::setAlias('@common', $root . '/common');
    $app = new yii\console\Application(['id' => 'market-race', 'basePath' => $root,
        'modules' => ['world' => ['class' => common\modules\world\Module::class, 'flags' => ['world_read' => true, 'world_write' => true, 'storage_v2' => true]]],
        'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => $dsn, 'username' => 'ablakin_ci', 'password' => 'ci-only-password', 'charset' => 'utf8'], 'authManager' => ['class' => yii\rbac\DbManager::class]]]);
    $app->params['remote_db'] = $app->db; $app->setModule('user', ['class' => dektrium\user\Module::class]);
    $app->db->open(); workerReady(); $input = json_decode(base64_decode($argv[4], true), true, 32, JSON_THROW_ON_ERROR);
    try {
        common\modules\world\models\Command::run((int)$argv[2], $argv[3], 'world.market.trade', $input,
            static fn($op) => (new common\modules\world\models\domain\NpcMarket($app->db))->trade((int)$argv[2], $input, $op));
        echo 'ok';
    } catch (common\modules\world\support\GameError | yii\web\ConflictHttpException $error) { echo 'unavailable'; }
    exit;
}
require __DIR__ . '/world-balance.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . PHP_EOL); exit(1); });

use common\modules\world\models\Command;
use common\modules\world\models\domain\{EconomyRuntime, MarketPricing, MarketRestocking, NpcInfrastructure, NpcMarket};
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage, StorageAccessPolicy};
use common\modules\world\modules\economy\value\Money;
use yii\db\Query;

require_once $yiiRoot . '/console/world-migrations/m261106_190000_world_npc_market.php';
ob_start(); try { $migration = new m261106_190000_world_npc_market(['db' => $db]); $migration->up(); $migration->up(); } finally { ob_end_clean(); }
$db->schema->refresh();
$npc = new NpcInfrastructure($db); $policy = $npc->policy(); $city = (int)$policy['city_node_id'];
$service = new NpcMarket($db); $prices = MarketPricing::unit('4.0000', $candidate['market']);
economyCheck($prices['buy_cr'] === '5.0000' && $prices['sell_cr'] === '1.8000' && $prices['sell_burn_cr'] === '0.2000', 'exact spread includes city, owner, delivery and buyback burn');
$stone = (new Query())->from('craft_item')->where(['code' => 'classic-stone'])->one($db);
$warehouse = (new Query())->select('s.*')->from(['s' => 'craft_storage'])->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.code' => 'npc-bot-warehouse', 's.kind' => 'stockpile'])->one($db);
$db->createCommand()->update('persone', ['credit' => '5000.0000'], ['user_id' => $npcUser])->execute();
$fixture = static function (callable $handler) use ($npcUser): array { return Command::run($npcUser, bin2hex(random_bytes(16)), 'fixture.market', [], $handler, true); };
$goodsBeforeDelivery = (int)(new Query())->from('craft_inventory')->where(['item_id' => $stone['id']])->sum('item_quantity', $db);
$fixture(static function ($op) use ($db, $npcUser, $warehouse, $stone, $policy, $npc) {
    $store = new CraftStorage($db); $store->operationId = $op; $inv = new CanonicalInventory($store);
    $target = (new StorageAccessPolicy($db))->storage($npcUser, (int)$warehouse['id'], true);
    $plan = $inv->planCraft([], $target, [], [], $stone, 20); $inv->applyCraft($npcUser, $plan, $target, [], $stone, 'fixture');
    (new MarketRestocking($db))->replenish($policy, $npc->nodes($policy), $op); return [];
});
economyCheck((int)(new Query())->from('craft_inventory')->where(['item_id' => $stone['id']])->sum('item_quantity', $db) === $goodsBeforeDelivery + 20, 'delivery only transfers the twenty fixture units and pre-existing stock');
$newBuyer = static function (int $id) use ($db, $app, $testRole): void {
    $db->createCommand()->insert('user', ['id' => $id, 'username' => 'Market' . $id])->execute();
    $db->createCommand()->insert('persone', ['id' => $id, 'user_id' => $id, 'credit' => '250.0000', 'balance' => '0.0000'])->execute();
    $app->authManager->assign($testRole, $id);
    $db->transaction(static function () use ($db, $id) { (new CanonicalInventory(new CraftStorage($db)))->initialize($id); });
};
$newBuyer(35001); $newBuyer(35002);
$state = $service->listing($city, 35001); $offer = array_values(array_filter($state['buy']['items'], static fn($r) => $r['item_id'] === (int)$stone['id']))[0];
economyCheck($offer['quantity'] >= 10, 'periodic delivery moves warehouse goods to real market slots');
$beforeStock = (int)(new Query())->from('craft_inventory')->where(['item_id' => $stone['id']])->sum('item_quantity', $db);
$market = (new Query())->from('world_npc_market')->where(['city_id' => $city])->one($db);
$snapshot = (new Query())->from('craft_inventory')->orderBy('id')->all($db); $beforeFunds = $npcTotal();
$service->listing($city, 35001);
$fixture(static function ($op) use ($db, $npc, $policy) { (new MarketRestocking($db))->replenish($policy, $npc->nodes($policy), $op); return []; });
economyCheck($snapshot === (new Query())->from('craft_inventory')->orderBy('id')->all($db) && $beforeFunds === $npcTotal(), 'reads and repeated delivery in the same interval do not mint goods or money');
$input = ['city_id' => $city, 'direction' => 'buy', 'quantity' => 1, 'inventory_id' => $offer['inventory_id'], 'profile_version' => $state['market']['profile_version'], 'unit_price_cr' => $offer['prices']['buy_cr']];
$trade = static function (int $user, array $input, ?string $key = null) use ($service): array { return Command::run($user, $key ?? bin2hex(random_bytes(16)), 'world.market.trade', $input, static fn($op) => $service->trade($user, $input, $op)); };
$key = bin2hex(random_bytes(16)); $result = $trade(35001, $input, $key);
economyCheck($trade(35001, $input, $key) === $result, 'identical retry returns the same receipt');
economyCheck(Money::parse($beforeFunds)->subtract(Money::parse($npcTotal()))->decimal() === $offer['prices']['delivery_cr'], 'purchase burns delivery exactly once; goods, owner and city receipts conserve the rest');
economyCheck((int)(new Query())->from('craft_inventory')->where(['item_id' => $stone['id']])->sum('item_quantity', $db) === $beforeStock, 'purchase conserves physical inventory');
$state = $service->listing($city, 35001); $sell = $state['sell']['items'][0];
$sellInput = array_replace($input, ['direction' => 'sell', 'inventory_id' => $sell['inventory_id'], 'unit_price_cr' => $sell['prices']['sell_cr']]);
$beforeFunds = $npcTotal(); $result = $trade(35001, $sellInput);
economyCheck(Money::parse($beforeFunds)->subtract(Money::parse($npcTotal()))->decimal() === $sell['prices']['sell_burn_cr'], 'buyback burns only the disclosed fee, funded by the bot');
$tx = $db->beginTransaction();
try {
    $full = new CraftStorage($db); $full->move(35002, $stone, 20 * (int)$stone['stack_size']);
    $before = $npcTotal(); economyReject(static fn() => $trade(35002, $input), 'MARKET_STORAGE_FULL');
    economyCheck($before === $npcTotal(), 'full backpack causes no payment or stock loss');
} finally { $tx->rollBack(); }
economyReject(static fn() => $trade(35002, array_replace($input, ['unit_price_cr' => '0.0000'])), 'MARKET_PRICE_CHANGED');
$tx = $db->beginTransaction();
try {
    $db->createCommand()->update('persone', ['credit' => '0.0000'], ['user_id' => 35002])->execute();
    $stockBefore = (new Query())->from('craft_inventory')->orderBy('id')->all($db); $moneyBefore = $npcTotal(); $failed = false;
    try { $trade(35002, $input); } catch (Throwable $error) { $failed = true; }
    economyCheck($failed && $stockBefore === (new Query())->from('craft_inventory')->orderBy('id')->all($db) && $moneyBefore === $npcTotal(), 'insufficient buyer funds roll back transferred inventory and every ledger line');
} finally { $tx->rollBack(); }

$tx = $db->beginTransaction();
try {
    $db->createCommand()->update('persone', ['credit' => $candidate['market']['bot_reserve_cr']], ['user_id' => $npcUser])->execute();
    $trade(35001, $input); $sell = ['inventory_id' => (int)(new Query())->select('id')->from('craft_inventory')->where(['user_id' => 35001, 'item_id' => $stone['id']])->andWhere(['>', 'item_quantity', 0])->scalar($db)];
    $db->createCommand()->update('persone', ['credit' => $candidate['market']['bot_reserve_cr']], ['user_id' => $npcUser])->execute();
    economyReject(static fn() => $trade(35001, array_replace($sellInput, ['inventory_id' => $sell['inventory_id']])), 'MARKET_FUNDS_LOW');
} finally { $tx->rollBack(); }
if ($db->driverName !== 'sqlite') {
    $db->createCommand()->update('craft_inventory', ['item_quantity' => 1], ['id' => $offer['inventory_id']])->execute();
    $before = $npcTotal(); $receipts = (int)(new Query())->from('world_market_trade')->count('*', $db); $children = [];
    foreach ([35001, 35002] as $user) {
        $command = [PHP_BINARY]; if (php_ini_loaded_file()) $command = array_merge($command, ['-c', php_ini_loaded_file()]);
        $command = array_merge($command, [__FILE__, 'market-worker', (string)$user, bin2hex(random_bytes(16)), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))]);
        $pipes = []; $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start market worker'); $children[] = [$process, $pipes];
    }
    releaseWorkers($children); $results = [];
    foreach ($children as [$process, $pipes]) {
        $out = trim(stream_get_contents($pipes[1])); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($out . $err); $results[] = $out;
    }
    economyCheck(count(array_filter($results, static fn($r) => $r === 'ok')) === 1 && (int)(new Query())->from('world_market_trade')->count('*', $db) === $receipts + 1, 'two simultaneous buyers: only one gets the last item and one receipt');
    economyCheck(Money::parse($before)->subtract(Money::parse($npcTotal()))->decimal() === $offer['prices']['delivery_cr'], 'race loser pays nothing; one delivery fee is burned');
}
echo "NPC market checks passed.\n";
