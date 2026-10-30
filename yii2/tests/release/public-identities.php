<?php
// Public owner projection: isolated in-memory database, no application config.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
use common\services\user\PublicProfile;
use common\services\user\UserActivity;

$app = new yii\console\Application([
    'id' => 'public-identities-test', 'basePath' => dirname(__DIR__, 2),
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:', 'tablePrefix' => 'identity_test_']],
]);
$db = $app->db;
$db->createCommand()->createTable('{{%user}}', ['id' => 'pk', 'username' => 'string', 'latest_activity' => 'datetime', 'auth_key' => 'string', 'password_hash' => 'string'])->execute();
foreach ([1 => time(), 2 => time() - 600, 3 => time()] as $id => $activity) {
    $db->createCommand()->insert('{{%user}}', ['id' => $id, 'username' => 'user-' . $id, 'latest_activity' => UserActivity::date($activity), 'auth_key' => 'secret', 'password_hash' => 'secret'])->execute();
}
$actual = PublicProfile::identities($db, [1, 1, 2, null, 0, 999]);
$expected = [1 => ['id' => 1, 'username' => 'user-1', 'is_online' => true], 2 => ['id' => 2, 'username' => 'user-2', 'is_online' => false]];
if ($actual !== $expected) throw new RuntimeException('Identity projection must include only requested public fields and actual presence.');
if (PublicProfile::identities($db, []) !== []) throw new RuntimeException('Empty owner list must stay empty.');
echo "Public identity projection checks passed\n";
