<?php

// Only an in-memory database and an explicitly created temporary runtime directory.
// Does not load application config, .env, or the deployed runtime/revision marker.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);

use api\controllers\HealthController;
use yii\web\Application;

function healthCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}

function healthResponse(HealthController $controller): array
{
    Yii::$app->response->setStatusCode(200);
    Yii::$app->response->headers->removeAll();
    ob_start();
    try {
        $data = $controller->actionIndex();
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    return [Yii::$app->response->statusCode, $data, $output];
}

function healthUnavailable(HealthController $controller, string $label): void
{
    list($status, $data, $output) = healthResponse($controller);
    healthCheck($status === 503 && $data === ['status' => 'unavailable'] && $output === '', $label);
    if (Yii::$app->response->headers->get('Cache-Control') !== 'no-store') {
        throw new RuntimeException('Health failures must not be cached.');
    }
}

$runtime = tempnam(sys_get_temp_dir(), 'ablaki-health-');
if ($runtime === false || !unlink($runtime) || !mkdir($runtime, 0700)) {
    throw new RuntimeException('Cannot create isolated health runtime.');
}
$marker = $runtime . '/deploy-version.txt';
try {
    $app = new Application([
        'id' => 'health-regression', 'basePath' => dirname(__DIR__, 2), 'runtimePath' => $runtime,
        'vendorPath' => dirname(__DIR__, 2) . '/vendor',
        'components' => [
            'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
            'request' => ['cookieValidationKey' => 'isolated-health-test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
            'response' => ['format' => yii\web\Response::FORMAT_JSON],
        ],
    ]);
    $controller = new HealthController('health', $app);
    $db = $app->db;
    healthUnavailable($controller, 'missing revision returns 503 without output or trace');
    file_put_contents($marker, 'invalid-revision');
    healthUnavailable($controller, 'invalid revision returns 503');
    $sha = str_repeat('a1', 20);
    file_put_contents($marker, $sha . PHP_EOL);
    healthUnavailable($controller, 'missing schema tables return 503');

    $db->createCommand('CREATE TABLE user_presence (user_id INTEGER PRIMARY KEY, last_seen_at INTEGER)')->execute();
    healthUnavailable($controller, 'presence alone does not declare the API ready');
    $db->createCommand('CREATE TABLE forum_comment_gift (id INTEGER PRIMARY KEY, user_id INTEGER, recipient_id INTEGER, created_at INTEGER)')->execute();
    healthUnavailable($controller, 'missing gift comment_id column returns 503');
    $db->createCommand('DROP TABLE forum_comment_gift')->execute();
    $db->createCommand('CREATE TABLE forum_comment_gift (id INTEGER PRIMARY KEY, comment_id INTEGER, user_id INTEGER, recipient_id INTEGER, created_at INTEGER)')->execute();
    $db->createCommand('DROP TABLE user_presence')->execute();
    $db->createCommand('CREATE TABLE user_presence (user_id INTEGER PRIMARY KEY)')->execute();
    healthUnavailable($controller, 'missing presence last_seen_at column returns 503');
    $db->createCommand('DROP TABLE user_presence')->execute();
    $db->createCommand('CREATE TABLE user_presence (user_id INTEGER PRIMARY KEY, last_seen_at INTEGER)')->execute();
    list($status, $data, $output) = healthResponse($controller);
    healthCheck($status === 200 && $data === ['status' => 'ok', 'revision' => $sha, 'portalListsVersion' => 1]
        && $output === '', 'ready schema returns 200, exact deployed SHA and contract version');
    healthCheck($app->response->headers->get('Cache-Control') === 'no-store', 'successful readiness response cannot be cached');

    $app->set('db', new class extends yii\base\Component {
        public function getTableSchema($table, $refresh = false)
        {
            throw new RuntimeException('Private database connection failure; never expose this trace.');
        }
    });
    healthUnavailable($controller, 'database exception is reduced to 503 without error details or trace');
    echo "Health checks passed using isolated SQLite and temporary runtime only.\n";
} finally {
    if (isset($db)) $db->close();
    if (is_file($marker)) unlink($marker);
    if (is_dir($runtime)) rmdir($runtime);
}
