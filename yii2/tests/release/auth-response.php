<?php

// Real login actions and serializer, using only an in-memory SQLite database.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);

use api\modules\v1\models\User;
use yii\web\Application;

function authResponseCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}

Yii::$container->set(\dektrium\user\models\User::class, User::class);
Yii::$container->set(\yii\rest\Serializer::class, \api\components\ListSerializer::class);
$app = new Application([
    'id' => 'auth-response-test',
    'basePath' => dirname(__DIR__, 2),
    'controllerNamespace' => 'api\controllers',
    'modules' => ['user' => [
        'class' => \dektrium\user\Module::class,
        'modelMap' => ['User' => User::class],
        'debug' => false,
    ]],
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'fixture-only', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'hostInfo' => 'http://test.invalid'],
        'user' => ['identityClass' => User::class, 'enableSession' => false, 'enableAutoLogin' => false],
        'response' => ['format' => \yii\web\Response::FORMAT_JSON],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'i18n' => ['translations' => ['user*' => [
            'class' => \yii\i18n\PhpMessageSource::class,
            'basePath' => '@vendor/dektrium/yii2-user/messages',
        ]]],
    ],
]);
$app->request->getHeaders()->set('Accept', 'application/json');
$db = $app->db;
try {
    $db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT, email TEXT,
        password_hash TEXT, auth_key TEXT, created_at INTEGER, updated_at INTEGER,
        last_login_at INTEGER, confirmed_at INTEGER, blocked_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE persone (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE,
        bonus_count INTEGER, refovod INTEGER, rating NUMERIC, balance NUMERIC, credit NUMERIC)')->execute();
    $db->createCommand()->insert('user', [
        'id' => 1, 'username' => 'FixtureUser', 'email' => 'fixture@example.invalid',
        'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT),
        'auth_key' => 'fixture-initial-key', 'created_at' => 1, 'updated_at' => 1,
        'last_login_at' => 0, 'confirmed_at' => 1, 'blocked_at' => null,
    ])->execute();
    $db->createCommand()->insert('persone', [
        'id' => 1, 'user_id' => 1, 'bonus_count' => 2, 'refovod' => 0,
        'rating' => 1.25, 'balance' => 123.5, 'credit' => 7,
    ])->execute();

    foreach ([false, true] as $hasDescription) {
        $expectedDescription = $hasDescription ? 'Existing profile text' : null;
        if ($hasDescription) {
            $db->createCommand('ALTER TABLE persone ADD COLUMN description TEXT')->execute();
            $db->createCommand()->update('persone', ['description' => $expectedDescription], ['id' => 1])->execute();
            $db->schema->refreshTableSchema('persone');
        }
        $schema = $hasDescription ? 'current schema' : 'legacy schema without description';
        foreach (['login-key', 'login'] as $action) {
            $app->user->setIdentity(null);
            $app->response->setStatusCode(200);
            $key = $db->createCommand('SELECT auth_key FROM user WHERE id=1')->queryScalar();
            $_SERVER['REQUEST_METHOD'] = $action === 'login' ? 'POST' : 'GET';
            $app->request->setBodyParams(['login' => 'FixtureUser', 'password' => 'fixture-password', 'rememberMe' => 0]);
            $response = $app->runAction('site/' . $action, $action === 'login-key' ? ['key' => $key] : []);
            authResponseCheck($app->response->statusCode === 200 && $response['user']['id'] === 1
                && $app->user->id === 1, $action . ' returns a serialized authenticated user on ' . $schema);
            authResponseCheck(array_key_exists('description', $response['user']['person'])
                && $response['user']['person']['description'] === $expectedDescription, 'optional description is preserved on ' . $schema);
            authResponseCheck($response['user']['email'] === 'fixture@example.invalid'
                && (float)$response['user']['person']['balance'] === 123.5
                && (float)$response['user']['person']['credit'] === 7.0, 'owner receives email and account values');
            authResponseCheck($response['token'] === $db->createCommand('SELECT auth_key FROM user WHERE id=1')->queryScalar(), 'returned token matches persisted credentials');
            if ($action === 'login-key') authResponseCheck($response['token'] !== $key, 'existing key rotation is preserved');
        }
        $app->user->setIdentity(null);
        $public = User::findOne(1)->toArray();
        authResponseCheck(!array_key_exists('email', $public) && !array_key_exists('credit', $public['person'])
            && !array_key_exists('balance', $public['person']), 'public serialization retains private-field restrictions on ' . $schema);
    }
    $db->createCommand()->update('persone', ['description' => null], ['id' => 1])->execute();
    authResponseCheck(User::findOne(1)->toArray()['person']['description'] === null, 'nullable description stays null on current schema');
    $db->createCommand()->update('persone', ['description' => ''], ['id' => 1])->execute();
    authResponseCheck(User::findOne(1)->toArray()['person']['description'] === '', 'empty description stays empty');
    $app->user->setIdentity(null);
    $invalid = $app->runAction('site/login-key', ['key' => 'invalid-fixture-key']);
    authResponseCheck($app->user->isGuest && isset($invalid['errors'])
        && !array_key_exists('user', $invalid) && !array_key_exists('token', $invalid), 'invalid key still rejects login');
    echo "Authentication response regression passed without any live API or database.\n";
} finally {
    $db->close();
}
