<?php
/** Run only through world-production-activate.sh, with the exclusive external writer lease. */
define('YII_DEBUG', false);
define('YII_ENV', 'prod');
define('YII_ENABLE_ERROR_HANDLER', false);
$root = '/web/yii2';
set_exception_handler(static function (Throwable $error) use ($root): void {
    error_log((string)$error, 3, $root . '/common/runtime/financial-maintenance/activation-error.log');
    fwrite(STDERR, 'Production activation stopped: ' . get_class($error) . ". Financial operations remain paused.\n");
    exit(1);
});
if (PHP_SAPI !== 'cli' || realpath($root) !== $root || getenv('WORLD_PRODUCTION_ACTIVATE') !== 'confirmed-production-checkout'
    || getenv('WORLD_PRODUCTION_UPGRADE') !== 'confirmed-production-checkout' || getenv('WORLD_TEST_MODE') === '1') throw new RuntimeException('Invalid activation context.');
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';
$directory = common\services\user\FinancialMaintenance::directory();
if (!common\services\user\FinancialMaintenance::paused() || is_link($directory)) throw new RuntimeException('Financial pause required.');
$probe = fopen($directory . '/writers.lock', 'r');
if (!$probe || flock($probe, LOCK_SH | LOCK_NB)) throw new RuntimeException('External exclusive lease required.');
fclose($probe);
$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php', getLocalConfig($root . '/common/config/main-local.php'),
    require $root . '/console/config/main.php', getLocalConfig($root . '/console/config/main-local.php')
);
$config['modules']['world']['flags'] = array_fill_keys(['world_read','world_write','storage_v2','economy_tick'], true);
$app = new yii\console\Application($config);
$db = $app->db;
if ($db->driverName !== 'mysql' || $db->createCommand('SELECT DATABASE()')->queryScalar() !== 'ablaki'
    || strpos($db->createCommand('SELECT VERSION()')->queryScalar(), '10.1.48-MariaDB') !== 0) throw new RuntimeException('Unexpected target database.');
if ((int)$db->createCommand("SELECT GET_LOCK('ablaki.world.production-activation', 0)")->queryScalar() !== 1) throw new RuntimeException('Activation already running.');
register_shutdown_function(static function () use ($db): void {
    try { $db->createCommand("SELECT RELEASE_LOCK('ablaki.world.production-activation')")->queryScalar(); } catch (Throwable $ignored) {}
});
$db->createCommand('SET SESSION innodb_lock_wait_timeout=60')->execute();
$author = (int)(new yii\db\Query())->select('id')->from('user')->where(['username' => 'fedornabilkin'])->scalar($db);
$progress = static function (string $step, array $details): void {
    echo json_encode(['step' => $step] + $details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
};
$report = (new common\modules\world\support\ProductionWorldActivation($db, $progress))->run($author, (string)getenv('WORLD_STARTER_RESERVE'));
file_put_contents($directory . '/world-activation-verified.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$progress('world-verified', $report);
