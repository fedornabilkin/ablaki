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
$report = ['read_only' => true, 'php' => PHP_VERSION,
    'server' => $db->createCommand('SELECT VERSION() AS version,DATABASE() AS database_name')->queryOne(),
    'settings' => $db->createCommand("SHOW VARIABLES WHERE Variable_name IN ('sql_mode','lower_case_table_names','innodb_file_format','innodb_large_prefix','log_bin','time_zone','max_allowed_packet')")->queryAll(),
    'tables' => $db->createCommand('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->queryAll(),
    'columns' => $db->createCommand("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='persone' AND COLUMN_NAME='credit') OR (TABLE_NAME='history_balance' AND COLUMN_NAME IN ('credit','credit_up'))) ORDER BY TABLE_NAME,COLUMN_NAME")->queryAll(),
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
foreach (['user','persone','history_balance','history_rating','craft_inventory'] as $table) $report['row_counts'][$table] = (int)$db->createCommand('SELECT COUNT(*) FROM ' . $db->quoteTableName($table))->queryScalar();
foreach (['economy_wallet_rollout','world_registry','economy_registry'] as $table) {
    if (!$db->schema->getTableSchema($table, true)) continue;
    $columns = $table === 'economy_wallet_rollout' ? ['phase'] : ($table === 'world_registry' ? ['schema_version','world_read','world_write','storage_v2','economy_tick'] : ['wallet_ready']);
    $report[$table] = (new yii\db\Query())->select($columns)->from($table)->where(['id'=>1])->one($db);
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
