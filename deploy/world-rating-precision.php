<?php
/** Targeted migration only; requires the host's exclusive writer lease. */
define('YII_DEBUG', false);
define('YII_ENV', 'prod');
define('YII_ENABLE_ERROR_HANDLER', false);
$root = '/web/yii2';
set_exception_handler(static function (Throwable $error) use ($root): void {
    error_log((string)$error, 3, $root . '/common/runtime/financial-maintenance/rating-precision-error.log');
    fwrite(STDERR, 'Rating precision upgrade failed; writes remain paused. ' . get_class($error) . "\n");
    exit(1);
});
if (PHP_SAPI !== 'cli' || realpath($root) !== $root || getenv('WORLD_RATING_PRECISION') !== 'confirmed-production-checkout') throw new RuntimeException('Invalid rating upgrade context.');
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';
$directory = common\services\user\FinancialMaintenance::directory();
if (!common\services\user\FinancialMaintenance::paused() || is_link($directory)) throw new RuntimeException('Financial pause required.');
$lease = fopen($directory . '/writers.lock', 'r');
if (!$lease || flock($lease, LOCK_SH | LOCK_NB)) throw new RuntimeException('Exclusive external writer lease required.');
fclose($lease);
$config = yii\helpers\ArrayHelper::merge(require $root . '/common/config/main.php', getLocalConfig($root . '/common/config/main-local.php'), require $root . '/console/config/main.php', getLocalConfig($root . '/console/config/main-local.php'));
$app = new yii\console\Application($config); $db = $app->db;
if ($db->driverName !== 'mysql' || $db->createCommand('SELECT DATABASE()')->queryScalar() !== 'ablaki' || strpos($db->createCommand('SELECT VERSION()')->queryScalar(), '10.1.48-MariaDB') !== 0) throw new RuntimeException('Unexpected database.');
$db->createCommand('SET SESSION lock_wait_timeout=60')->execute();
if ((int)$db->createCommand("SELECT GET_LOCK('ablaki.world.production-preparation', 0)")->queryScalar() !== 1) throw new RuntimeException('Another upgrade is running.');
register_shutdown_function(static function () use ($db): void { try { $db->createCommand("SELECT RELEASE_LOCK('ablaki.world.production-preparation')")->queryScalar(); } catch (Throwable $ignored) {} });
foreach (['persone', 'history_rating'] as $table) {
    if (strtolower((string)$db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar()) !== 'innodb') throw new RuntimeException('Expected transactional rating tables.');
}
$precision = new common\services\user\LegacyRatingPrecision($db);
$baseline = $directory . '/rating-precision-before.json';
if (!is_file($baseline)) {
    file_put_contents($baseline . '.new', json_encode($precision->snapshot(), JSON_THROW_ON_ERROR));
    rename($baseline . '.new', $baseline);
}
$before = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
if ($precision->snapshot() !== $before) throw new RuntimeException('Rating baseline changed while writes should be paused.');
$version = 'm261101_130000_world_rating_precision';
require $root . '/console/world-migrations/' . $version . '.php';
if ((new $version(['db' => $db]))->up() === false) throw new RuntimeException('Rating migration failed.');
if ($precision->snapshot() !== $before) throw new RuntimeException('Rating migration changed stored values.');
if (!(new yii\db\Query())->from('migration')->where(['version' => $version])->exists($db)) $db->createCommand()->insert('migration', ['version' => $version, 'apply_time' => time()])->execute();
$report = ['migration' => $version, 'preserved' => $before, 'completed_at' => gmdate('c')];
file_put_contents($directory . '/rating-precision-verified.json.new', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
rename($directory . '/rating-precision-verified.json.new', $directory . '/rating-precision-verified.json');
echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
