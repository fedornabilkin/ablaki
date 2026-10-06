<?php
// Only the restored disposable PostgreSQL copy created by world-open-test.sh.
$database = getenv('WORLD_VERIFY_DATABASE');
if (!preg_match('/^ablaki_world_verify_[0-9]+_[0-9]+$/D', $database ?: '')
    || getenv('WORLD_TEST_SETUP') !== 'confirmed-test-checkout'
    || getenv('WORLD_TEST_MODE') !== '1'
    || getenv('WORLD_INSTALL') !== 'confirmed-world-install') {
    throw new RuntimeException('Dedicated test-copy context required.');
}
$root = dirname(__DIR__) . '/yii2';
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';
$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php',
    getLocalConfig($root . '/common/config/main-local.php'),
    require $root . '/console/config/main.php',
    getLocalConfig($root . '/console/config/main-local.php')
);
$dsn = $config['components']['db']['dsn'] ?? '';
if (strpos($dsn, 'pgsql:') !== 0 || !preg_match('/\bdbname=([^;]+)/', $dsn, $match) || $match[1] === $database) {
    throw new RuntimeException('Expected an established PostgreSQL database distinct from the verification copy.');
}
// Override after local configuration, before Yii bootstrap can open a connection.
$config['components']['db']['dsn'] = preg_replace('/\bdbname=[^;]+/', 'dbname=' . $database, $dsn);
$config['components']['db']['enableSchemaCache'] = false;
$config['runtimePath'] = sys_get_temp_dir() . '/' . $database;
$app = new yii\console\Application($config);
$db = $app->db;
if ($db->driverName !== 'pgsql' || $db->createCommand('SELECT current_database()')->queryScalar() !== $database) {
    throw new RuntimeException('Verification connection mismatch.');
}
$snapshots = [];
foreach (['craft_inventory', 'economy_account', 'persone', 'history_balance', 'world_slot', 'world_slot_entitlement'] as $table) {
    if (!$db->schema->getTableSchema($table, true)) continue;
    $primary = $db->schema->getTableSchema($table)->primaryKey;
    if (!$primary) throw new RuntimeException('Missing snapshot key: ' . $table);
    foreach ((new yii\db\Query())->from($table)->each(500, $db) as $row) {
        $key = array_intersect_key($row, array_flip($primary));
        $snapshots[$table][] = [$key, $row];
    }
}
$owners = (new yii\db\Query())->select(['id', 'name', 'owner_user_id'])->from('world_node')->indexBy('id')->all($db);
for ($pass = 1; $pass <= 2; $pass++) {
    if ($app->runAction('world-setup/test-ready') !== 0) throw new RuntimeException('Test-copy installation failed.');
    foreach ($snapshots as $table => $rows) foreach ($rows as list($key, $before)) {
        $after = (new yii\db\Query())->from($table)->where($key)->one($db);
        if ($after !== $before) throw new RuntimeException('Test-copy data mismatch: ' . $table);
    }
    foreach ($owners as $id => $before) {
        $after = (new yii\db\Query())->select(['id', 'name', 'owner_user_id'])->from('world_node')->where(['id' => $id])->one($db);
        if ($after !== $before) throw new RuntimeException('Test-copy object ownership mismatch.');
    }
    $invalid = (new yii\db\Query())->from(['n' => 'world_node'])
        ->leftJoin(['p' => 'world_node'], '[[p.id]]=[[n.parent_id]]')
        ->where('[[n.hierarchy_level]] IS NULL OR [[n.template_id]] IS NULL OR ([[n.parent_id]] IS NOT NULL AND ([[p.id]] IS NULL OR [[n.hierarchy_level]]<>[[p.hierarchy_level]]+1))')
        ->exists($db);
    if ($invalid) throw new RuntimeException('Test-copy hierarchy verification failed.');
    echo 'PASS restored test copy, installation pass ' . $pass . ', existing property, credits and owners retained.' . PHP_EOL;
}
