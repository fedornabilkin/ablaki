<?php
require dirname(__DIR__, 2) . '/common/modules/world/modules/economy/value/Money.php';
require dirname(__DIR__, 2) . '/common/modules/world/modules/economy/value/LegacyCreditConversion.php';
require dirname(__DIR__, 2) . '/common/modules/world/modules/economy/value/TestCreditConversion.php';

use common\modules\world\modules\economy\value\Money;
use common\modules\world\modules\economy\value\TestCreditConversion;

foreach (['0' => '0.0000', '1.2300000000000002' => '1.2300', '12.34565' => '12.3457', '-12.34565' => '-12.3457', '9.99995' => '10.0000', '5e-5' => '0.0001', '4.999e-5' => '0.0000', '1.23456e2' => '123.4560', '0001.20' => '1.2000', '-0.0000' => '0.0000'] as $raw => $expected) {
    if (TestCreditConversion::amount((string)$raw)->decimal() !== $expected) throw new RuntimeException('Wrong test conversion: ' . $raw);
}
foreach (['-0.00000001', '-1', 'NaN', 'Infinity', '', '1e999'] as $raw) {
    try { TestCreditConversion::amount($raw, true); }
    catch (InvalidArgumentException | OverflowException $e) { continue; }
    throw new RuntimeException('Invalid personal value was accepted: ' . $raw);
}
try { Money::parse('1.23456'); throw new RuntimeException('Production parser became permissive.'); }
catch (InvalidArgumentException $e) {}
echo "PASS test credit rounding, exponent notation, overflow, negative balances and strict production parser\n";

// Optional target-server regression. Never loads application configuration or a live DSN.
if (!getenv('ABL_TEST_DSN')) return;
if (getenv('ABL_TEST_DSN') !== 'mysql:host=127.0.0.1;port=3306;dbname=ablakin_ci') throw new RuntimeException('Requires disposable loopback ablakin_ci.');
define('YII_ENABLE_ERROR_HANDLER', false);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', $root . '/common');
Yii::setAlias('@console', $root . '/console');
$app = new yii\console\Application(['id' => 'wallet-preparation-test', 'basePath' => $root,
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => getenv('ABL_TEST_DSN'),
        'username' => 'ablakin_ci', 'password' => 'ci-only-password', 'charset' => 'utf8mb4']]]);
$db = $app->db;
$db->open();
if ($db->schema->getTableNames()) throw new RuntimeException('Wallet fixture requires an empty isolated database.');
foreach (['user', 'history_rating', 'comission', 'game_saper', 'game_five_hod', 'forum_comment', 'forum_comment_gift'] as $table) {
    $db->createCommand("CREATE TABLE $table (id INT PRIMARY KEY) ENGINE=" . (in_array($table, ['user', 'forum_comment_gift'], true) ? 'InnoDB' : 'MyISAM'))->execute();
}
$db->createCommand('CREATE TABLE persone (id INT PRIMARY KEY,user_id INT NOT NULL,credit FLOAT NOT NULL DEFAULT 0) ENGINE=InnoDB')->execute();
$db->createCommand('CREATE TABLE history_balance (id INT PRIMARY KEY,credit FLOAT NOT NULL DEFAULT 0,credit_up FLOAT NOT NULL DEFAULT 0) ENGINE=MyISAM')->execute();
foreach (['game_orel', 'game_duel', 'game_five'] as $table) $db->createCommand("CREATE TABLE $table (id INT PRIMARY KEY,kon DECIMAL(19,4),user_gamer INT,status VARCHAR(24)) ENGINE=MyISAM")->execute();
foreach (['credit_exchange' => 'credit', 'credit_transfer' => 'amount'] as $table => $column) $db->createCommand("CREATE TABLE $table (id INT PRIMARY KEY,$column FLOAT,user_buyer INT) ENGINE=MyISAM")->execute();
$db->createCommand('CREATE TABLE advertising (id INT PRIMARY KEY,credit FLOAT) ENGINE=MyISAM')->execute();
$db->createCommand('CREATE TABLE economy_registry (id INT PRIMARY KEY,wallet_ready TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB')->execute();
$db->createCommand('INSERT INTO economy_registry VALUES (1,0)')->execute();
$db->createCommand('INSERT INTO user VALUES (1)')->execute();
$db->createCommand('INSERT INTO persone VALUES (1,1,0.123456)')->execute();
$db->createCommand('INSERT INTO history_balance VALUES (1,0.123456,-0.123456)')->execute();
$db->createCommand('INSERT INTO advertising VALUES (1,-1)')->execute();
foreach (['m260927_106000_wallet_rollout', 'm261031_210000_wallet_precision_policy'] as $migration) {
    require $root . '/console/world-migrations/' . $migration . '.php';
    (new $migration(['db' => $db]))->up();
}
$directory = sys_get_temp_dir() . '/wallet-preparation-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$preparation = new common\modules\world\support\ProductionDatabasePreparation($db, $directory);
$progress = static function (string $phase, array $details): void { echo 'PASS ', $phase, ' ', json_encode($details), "\n"; };
$preparation->prepareEngines($progress);
// Simulate a crash after DDL committed, before the verified checkpoint was persisted.
$file = $directory . '/engine-history_balance.json';
$checkpoint = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$checkpoint['verified'] = false;
file_put_contents($file, json_encode($checkpoint));
$preparation->prepareEngines($progress);
putenv('WORLD_INSTALL=confirmed-world-install');
putenv('WORLD_WALLET_MAINTENANCE=1');
$wallet = new common\modules\world\modules\economy\models\domain\WalletRollout($db);
$reject = static function (callable $action, string $prefix): void {
    try { $action(); } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), $prefix)) { echo 'PASS ', $prefix, "\n"; return; }
        throw $e;
    }
    throw new RuntimeException('Expected rejection: ' . $prefix);
};
$wallet->begin();
$reject(static fn() => $wallet->snapshot(), 'Explicit amount decision required');
if ((int)$db->createCommand('SELECT COUNT(*) FROM economy_wallet_snapshot')->queryScalar() !== 0) throw new RuntimeException('Failed snapshot did not roll back.');
$wallet->cancel(); $wallet->begin('half-up-4');
do { $status = $wallet->snapshot(); } while ($status['rollout']['phase'] !== 'snapshotted');
$reject(static fn() => $wallet->begin('strict'), 'Cannot change the precision policy');
$db->createCommand("UPDATE economy_wallet_rollout SET phase='converting' WHERE id=1")->execute();
$db->createCommand("UPDATE economy_wallet_column SET phase='converting' WHERE column_key='persone.credit'")->execute();
$db->createCommand('ALTER TABLE persone MODIFY credit DECIMAL(19,4) NOT NULL DEFAULT 0')->execute();
$db->schema->refresh();
$db->createCommand("UPDATE economy_wallet_snapshot SET exact_amount=0.9999 WHERE column_key='persone.credit'")->execute();
$reject(static fn() => $wallet->convert(), 'Snapshot precision decision changed');
$db->createCommand("UPDATE economy_wallet_snapshot SET exact_amount=0.1235 WHERE column_key='persone.credit'")->execute();
$preparation->prepareWallet($progress);
$preparation->prepareWallet($progress);
if ($db->createCommand('SELECT credit FROM persone WHERE id=1')->queryScalar() !== '0.1235'
    || $db->createCommand('SELECT credit_up FROM history_balance WHERE id=1')->queryScalar() !== '-0.1235'
    || (string)$db->createCommand('SELECT credit FROM advertising WHERE id=1')->queryScalar() !== '-1') throw new RuntimeException('Preparation changed an approved amount.');
foreach (glob($directory . '/*.json') as $file) unlink($file);
rmdir($directory);
// Leave this dedicated fixture empty for the following target-database suite.
foreach ($db->schema->getTableNames('', true) as $table) $db->createCommand()->dropTable($table)->execute();
echo "PASS MariaDB engine checkpoints, committed DDL recovery, snapshot integrity and idempotent exact wallet preparation\n";
