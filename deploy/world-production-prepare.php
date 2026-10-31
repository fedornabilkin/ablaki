<?php
/** Invoked by the path-checked shell runner while its exclusive writer lease is held. */
define('YII_DEBUG', false);
define('YII_ENV', 'prod');
define('YII_ENABLE_ERROR_HANDLER', false);
set_exception_handler(static function (Throwable $error): void {
    // Keep database details and source values out of CI logs.
    error_log((string)$error, 3, '/web/yii2/common/runtime/financial-maintenance/preparation-error.log');
    fwrite(STDERR, 'Production preparation stopped: ' . get_class($error) . ". Financial operations remain paused.\n");
    exit(1);
});
if (PHP_SAPI !== 'cli' || realpath('/web/yii2') !== '/web/yii2'
    || getenv('WORLD_PRODUCTION_UPGRADE') !== 'confirmed-production-checkout') throw new RuntimeException('Invalid preparation context.');
$root = '/web/yii2';
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';
$directory = common\services\user\FinancialMaintenance::directory();
if (!common\services\user\FinancialMaintenance::paused() || is_link($directory)) throw new RuntimeException('Financial pause is required.');
$probe = fopen($directory . '/writers.lock', 'r');
if (!$probe || flock($probe, LOCK_SH | LOCK_NB)) throw new RuntimeException('Exclusive external writer lease is required.');
fclose($probe);
$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php', getLocalConfig($root . '/common/config/main-local.php'),
    require $root . '/console/config/main.php', getLocalConfig($root . '/console/config/main-local.php')
);
$app = new yii\console\Application($config);
$db = $app->db;
if ($db->driverName !== 'mysql' || $db->createCommand('SELECT DATABASE()')->queryScalar() !== 'ablaki'
    || strpos($db->createCommand('SELECT VERSION()')->queryScalar(), '10.1.48-MariaDB') !== 0) throw new RuntimeException('Unexpected target database.');
if ((int)$db->createCommand('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->queryScalar() !== 0) throw new RuntimeException('Review database triggers before preparation.');
foreach (['WORLD_INSTALL' => 'confirmed-world-install', 'WORLD_WALLET_MAINTENANCE' => '1'] as $key => $value) putenv($key . '=' . $value);
$progress = static function (string $phase, array $details): void {
    echo json_encode(['phase' => $phase] + $details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
};
$preparation = new common\modules\world\support\ProductionDatabasePreparation($db, $directory);
$preparation->prepareEngines($progress);
$progress('installing-world-schema', []);
$controller = new common\modules\world\commands\WorldSetupController('world-setup', $app);
if ($controller->actionInstall() !== 0) throw new RuntimeException('World installation failed.');
$result = $preparation->prepareWallet($progress);
$flags = (new yii\db\Query())->select(['schema_version', 'world_read', 'world_write', 'storage_v2', 'economy_tick'])->from('world_registry')->where(['id' => 1])->one($db);
if (!$flags || (int)$flags['schema_version'] < common\modules\world\models\domain\WorldFlags::SCHEMA_VERSION) throw new RuntimeException('World schema is incomplete.');
foreach (['world_read', 'world_write', 'storage_v2', 'economy_tick'] as $flag) if ((int)$flags[$flag] !== 0) throw new RuntimeException('Unexpected world activation.');
$report = $result + ['world' => $flags, 'completed_at' => gmdate('c')];
if (file_put_contents($directory . '/verified.json.new', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false
    || !rename($directory . '/verified.json.new', $directory . '/verified.json')) throw new RuntimeException('Cannot save final verification.');
$progress('verified', ['wallet' => 'active', 'world_enabled' => false]);
