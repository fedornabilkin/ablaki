<?php
/** Read-only production preflight; no application actions or credential output. */
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Production preflight failed: ' . get_class($error) . "\n"); exit(1);
});
$root = '/web/yii2';
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';
$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php', getLocalConfig($root . '/common/config/main-local.php'),
    require $root . '/console/config/main.php', getLocalConfig($root . '/console/config/main-local.php')
);
$db = Yii::createObject($config['components']['db']);
if (!$db instanceof yii\db\Connection || $db->driverName !== 'mysql') throw new RuntimeException('Unexpected database driver.');
$db->open();
$db->createCommand('SET SESSION TRANSACTION READ ONLY')->execute();
if (getenv('WORLD_INSPECT_PROGRESS') === 'true') {
    $report = ['read_only' => true, 'progress_only' => true,
        'financial_pause' => is_file($root . '/common/runtime/financial-maintenance/paused'),
        'preparation_completed' => is_file($root . '/common/runtime/financial-maintenance/completed')];
    foreach (glob($root . '/common/runtime/financial-maintenance/engine-*.json') as $file) {
        $checkpoint = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $report['engines'][basename($file, '.json')] = (bool)$checkpoint['verified'];
    }
    if ($db->schema->getTableSchema('economy_wallet_rollout', true)) {
        $report['wallet'] = (new yii\db\Query())->select(['phase', 'updated_at'])->from('economy_wallet_rollout')->where(['id' => 1])->one($db);
        $report['columns'] = (new yii\db\Query())->select(['column_key', 'phase', 'row_count', 'last_source_id'])->from('economy_wallet_column')->all($db);
    }
    if ($db->schema->getTableSchema('world_storage_rollout')) {
        $report['storage'] = (new yii\db\Query())->select(['phase','activated_at'])->from('world_storage_rollout')->where(['id' => 1])->one($db);
        $report['storage']['migrated_users'] = (int)(new yii\db\Query())->from('world_storage_baseline')->count('*', $db);
        $report['storage']['verified_users'] = (int)(new yii\db\Query())->from('world_storage_baseline')->where(['not', ['verified_at' => null]])->count('*', $db);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
$report = ['read_only' => true, 'php' => PHP_VERSION,
    'server' => $db->createCommand('SELECT VERSION() AS version,DATABASE() AS database_name')->queryOne(),
    'settings' => $db->createCommand("SHOW VARIABLES WHERE Variable_name IN ('sql_mode','lower_case_table_names','innodb_file_format','innodb_large_prefix','log_bin','time_zone','max_allowed_packet')")->queryAll(),
    'tables' => $db->createCommand('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->queryAll(),
    'columns' => $db->createCommand("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='persone' AND COLUMN_NAME IN ('credit','rating')) OR (TABLE_NAME='history_balance' AND COLUMN_NAME IN ('credit','credit_up')) OR (TABLE_NAME='history_rating' AND COLUMN_NAME IN ('rating','rating_up'))) ORDER BY TABLE_NAME,COLUMN_NAME")->queryAll(),
    'triggers' => $db->createCommand('SELECT EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->queryAll(),
    'wallet_guard_available' => is_file($root . '/common/modules/world/modules/economy/models/domain/WalletMaintenanceBootstrap.php'),
    'financial_guard_available' => is_file($root . '/common/services/user/FinancialMaintenanceBootstrap.php'),
    'financial_pause' => is_file($root . '/common/runtime/financial-maintenance/paused'),
    'preparation_completed' => is_file($root . '/common/runtime/financial-maintenance/completed'),
    'container_pid1' => trim((string)@file_get_contents('/proc/1/comm')),
];
if (is_file($root . '/common/runtime/financial-maintenance/verified.json')) {
    $report['preparation'] = json_decode(file_get_contents($root . '/common/runtime/financial-maintenance/verified.json'), true, 512, JSON_THROW_ON_ERROR);
}
if (is_file($root . '/common/runtime/financial-maintenance/rating-precision-verified.json')) {
    $report['rating_precision'] = json_decode(file_get_contents($root . '/common/runtime/financial-maintenance/rating-precision-verified.json'), true, 512, JSON_THROW_ON_ERROR);
}
foreach (['user','persone','history_balance','history_rating','craft_inventory'] as $table) $report['row_counts'][$table] = (int)$db->createCommand('SELECT COUNT(*) FROM ' . $db->quoteTableName($table))->queryScalar();
foreach (['economy_wallet_rollout','world_registry','economy_registry'] as $table) {
    if (!$db->schema->getTableSchema($table, true)) continue;
    $columns = $table === 'economy_wallet_rollout' ? ['phase'] : ($table === 'world_registry' ? ['schema_version','world_read','world_write','storage_v2','economy_tick'] : ['wallet_ready']);
    $report[$table] = (new yii\db\Query())->select($columns)->from($table)->where(['id'=>1])->one($db);
}
$report['world_activation'] = [
    'environment_flags' => array_combine(['WORLD_READ','WORLD_WRITE','STORAGE_V2','ECONOMY_TICK','WORLD_TEST_MODE'], array_map(static fn($key) => getenv($key) === '1', ['WORLD_READ','WORLD_WRITE','STORAGE_V2','ECONOMY_TICK','WORLD_TEST_MODE'])),
    'configured_flags' => $config['modules']['world']['flags'] ?? [],
    'registry' => (new yii\db\Query())->from('world_registry')->where(['id' => 1])->one($db),
    'storage' => (new yii\db\Query())->from('world_storage_rollout')->where(['id' => 1])->one($db),
    'nodes' => (new yii\db\Query())->select(['id','parent_id','node_type','name','owner_user_id','status'])->from('world_node')->orderBy('id')->limit(100)->all($db),
    'economy_profile' => (new yii\db\Query())->select(['id','version','digest'])->from('world_economy_profile')->orderBy(['id' => SORT_DESC])->limit(1)->one($db),
    'npc' => (new yii\db\Query())->from('world_npc_policy')->all($db),
    'bot' => (new yii\db\Query())->select(['u.id','u.username','p.credit'])->from(['u'=>'user'])->leftJoin(['p'=>'persone'], 'p.user_id=u.id')->where(['u.username'=>'bot'])->one($db),
    'account_total' => (new yii\db\Query())->from('economy_account')->count('*', $db),
    'starter_reserves' => (new yii\db\Query())->from('world_starter_reserve')->all($db),
];
$moduleConfig = $config['modules']['world'];
unset($moduleConfig['class']);
$module = new common\modules\world\Module('world', null, $moduleConfig);
$report['world_activation']['capabilities'] = (new common\modules\world\models\domain\WorldFlags($db, $module))->capabilities();
$profile = (new yii\db\Query())->select('p.config_json')->from(['p' => 'world_economy_profile'])->innerJoin(['c' => 'world_economy_current'], 'c.profile_id=p.id')->where(['c.id' => 1])->scalar($db);
$report['world_activation']['public_access'] = $profile && (json_decode($profile, true, 64, JSON_THROW_ON_ERROR)['closed_test']['restricted'] ?? true) === false;
if ($report['world_activation']['public_access'] && $report['world_activation']['capabilities']['world_write']) {
    // Exercise the production read model without credentials, HTTP login, presence writes or gameplay commands.
    $config['components']['db'] = $db;
    $app = new yii\console\Application($config);
    $world = (int)$report['world_activation']['registry']['active_world_id'];
    $reader = new common\modules\world\models\domain\WorldQuery($db, new common\modules\world\models\domain\WorldAccessPolicy(0));
    $rootNode = $reader->node($world);
    $report['world_activation']['public_root'] = ['id' => $rootNode['id'], 'name' => $rootNode['name'], 'type' => $rootNode['type']];
    $report['world_activation']['npc_ready'] = (new common\modules\world\models\domain\NpcInfrastructure($db))->ready();
    foreach ($report['world_activation']['starter_reserves'] as $reserve) {
        $report['world_activation']['starter_availability'][] = (new common\modules\world\models\domain\StarterReserve($db))->state((int)$reserve['node_id']);
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
