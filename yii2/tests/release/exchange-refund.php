<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

$app = new \yii\console\Application([
    'id' => 'exchange-refund-test',
    'basePath' => dirname(__DIR__, 2),
    'components' => ['db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:']],
]);
$db = $app->db;
$db->createCommand('CREATE TABLE persone (id INTEGER PRIMARY KEY, user_id INTEGER)')->execute();
$db->createCommand('CREATE TABLE credit_exchange (id INTEGER PRIMARY KEY, user_id INTEGER,
    user_buyer INTEGER, credit NUMERIC, amount NUMERIC, type TEXT)')->execute();
$db->createCommand("INSERT INTO credit_exchange VALUES
    (1, 1, 0, 900, 7, 'sell'), (2, 1, 0, 40, 3, 'buy'),
    (3, 2, 0, 999, 999, 'sell'), (4, 1, 2, 800, 8, 'buy')")->execute();
$identity = new class extends \yii\base\BaseObject implements \yii\web\IdentityInterface {
    public static function findIdentity($id) { return null; }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return 1; }
    public function getAuthKey() { return ''; }
    public function validateAuthKey($authKey) { return false; }
};
$person = new \common\models\user\Person(['id' => 1, 'user_id' => 1]);
$person->populateRelation('user', $identity);
$data = new \common\modules\exchange\middleware\ExchangeDataMiddleware($person,
    new \common\modules\exchange\api\models\CreditExchange());
$middleware = new \common\modules\exchange\middleware\exchange\RemoveAllMiddleware();
$middleware::$data = $data;
if (!$middleware->check()) throw new \RuntimeException('Removal failed.');
if ((float)$data->changingCredit !== 40.0 || (float)$data->changingBalance !== 7.0) {
    throw new \RuntimeException('Refund must use credit from buy orders and balance from sell orders.');
}
$remaining = $db->createCommand('SELECT id FROM credit_exchange ORDER BY id')->queryColumn();
if (array_map('intval', $remaining) !== [3, 4]) {
    throw new \RuntimeException('Foreign and completed orders must remain intact.');
}
echo "PASS mixed-order cancellation calculates each currency from its own reserved orders\n";
echo "PASS foreign and completed orders are preserved\n";
echo "This checks refund selection; full exchange transaction/concurrency hardening is still pending.\n";
