<?php

// Disposable database only. Never loads application config, credentials, or production data.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');

use common\modules\forum\services\CommentGiftService;
use yii\db\Connection;

function giftConnection(string $file): Connection
{
    $db = new Connection(['dsn' => 'sqlite:' . $file]);
    $db->open();
    $db->createCommand('PRAGMA busy_timeout = 15000')->execute();
    return $db;
}
function giftCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}
function giftRejected(callable $call, string $label): void
{
    try { $call(); } catch (DomainException $expected) { giftCheck(true, $label); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
if (($argv[1] ?? '') === 'worker') {
    while (microtime(true) < (float)$argv[3]) usleep(1000);
    $result = (new CommentGiftService(giftConnection($argv[2])))->give(4, 4);
    echo $result['alreadyGiven'] ? 'already' : 'granted';
    exit;
}
$file = tempnam(sys_get_temp_dir(), 'ablakin-gifts-');
$processes = [];
try {
    $db = giftConnection($file);
    $db->createCommand('CREATE TABLE persone (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, credit NUMERIC NOT NULL, balance NUMERIC NOT NULL)')->execute();
    $db->createCommand('CREATE TABLE forum_comment (id INTEGER PRIMARY KEY, user_id INTEGER, active INTEGER, comment TEXT, theme_id INTEGER, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE forum_theme (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, view INTEGER NOT NULL, last_post INTEGER DEFAULT 0, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE forum_comment_gift (id INTEGER PRIMARY KEY, comment_id INTEGER NOT NULL, user_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, created_at INTEGER, UNIQUE(comment_id, user_id))')->execute();
    $db->createCommand('CREATE TABLE history_balance (id INTEGER PRIMARY KEY, user_id INTEGER, balance NUMERIC, credit NUMERIC, balance_up NUMERIC, credit_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT)')->execute();
    $db->createCommand("INSERT INTO user VALUES (1,'FirstDonor'), (2,'Author'), (3,'PoorDonor'), (4,'SecondDonor')")->execute();
    $db->createCommand('INSERT INTO persone VALUES (1,1,10,100), (2,2,3,200), (3,3,0,300), (4,4,5,400)')->execute();
    $db->createCommand("INSERT INTO forum_comment VALUES (1,2,1,'hello',1,1), (2,1,1,'own',1,2), (3,2,0,'inactive',1,3), (4,2,1,'concurrent',1,4), (5,2,1,'failure',1,5)")->execute();
    $service = new CommentGiftService($db);
    $first = $service->give(1, 1);
    giftCheck(!$first['alreadyGiven'] && $first['giftCount'] === 1 && $first['credit'] === 9.0, 'gift returns authoritative sender credit');
    giftCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=2')->queryScalar() === 4.0, 'recipient receives exactly one credit');
    giftCheck((int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 2, 'both accounts receive balance histories');
    giftCheck((float)$db->createCommand('SELECT SUM(credit_up) FROM history_balance')->queryScalar() === 0.0, 'gift conserves credits');
    giftCheck($service->give(1, 1)['alreadyGiven'] && (int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 2, 'repeat does not charge or duplicate history');
    giftRejected(function () use ($service) { $service->give(2, 1); }, 'own message is rejected');
    giftRejected(function () use ($service) { $service->give(1, 3); }, 'insufficient funds are rejected');
    giftRejected(function () use ($service) { $service->give(3, 1); }, 'inactive message is rejected');
    giftRejected(function () use ($service) { $service->give(999, 1); }, 'missing message is rejected');
    $db->pdo->exec("CREATE TRIGGER fail_history BEFORE INSERT ON history_balance WHEN NEW.user_id=2 BEGIN SELECT RAISE(ABORT, 'injected history failure'); END");
    try { $service->give(5, 4); throw new RuntimeException('Expected history failure'); }
    catch (\yii\db\Exception $expected) { /* Transaction must roll back both accounts and gift. */ }
    giftCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=4')->queryScalar() === 5.0 && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=2')->queryScalar() === 4.0, 'history failure restores both accounts');
    giftCheck((int)$db->createCommand('SELECT COUNT(*) FROM forum_comment_gift WHERE comment_id=5')->queryScalar() === 0 && (int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 2, 'history failure removes partial gift and history');
    $db->createCommand('DROP TRIGGER fail_history')->execute();
    $start = microtime(true) + 1;
    for ($i = 0; $i < 8; $i++) {
        $args = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
        // Ubuntu builds PDO as a shared extension; -n disables its usual pdo.ini.
        if (is_file(ini_get('extension_dir') . '/pdo.so')) {
            $args = array_merge($args, ['-d', 'extension=pdo']);
        }
        $args = array_merge($args, ['-d', 'extension=pdo_sqlite', __FILE__, 'worker', $file, (string)$start]);
        $pipes = [];
        $process = proc_open(implode(' ', array_map('escapeshellarg', $args)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, null, ['bypass_shell' => PHP_OS_FAMILY === 'Windows']);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $granted = 0;
    foreach ($processes as [$process, $pipes]) {
        $output = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Worker failure: ' . $output . $error);
        if ($output === 'granted') $granted++;
        elseif ($output !== 'already') throw new RuntimeException('Unexpected worker result: ' . $output);
    }
    $processes = [];
    giftCheck($granted === 1, 'eight concurrent retries create one gift');
    giftCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=4')->queryScalar() === 4.0 && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=2')->queryScalar() === 5.0, 'concurrent retries preserve exact account changes');
    giftCheck((int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 4, 'concurrent retries create one history pair');
    new \yii\console\Application(['id' => 'gift-regression', 'basePath' => dirname(__DIR__, 2), 'components' => ['db' => $db]]);
    $comment = \api\modules\v1\models\forum\Comment::findOne(1);
    giftCheck($comment->toArray()['gift_count'] === 1 && $comment->toArray()['gifted_by_me'] === false, 'public comment contains gift totals without claiming guest ownership');
    $comment->scenario = 'update';
    $comment->load(['comment' => 'edited', 'user_id' => 77, 'theme_id' => 77, 'active' => 0], '');
    giftCheck((int)$comment->user_id === 2 && (int)$comment->theme_id === 1 && (int)$comment->active === 1, 'comment edits cannot replace recipient or message ownership');
    Yii::$app->set('user', new class extends \yii\base\Component {
        public function getId() { return 1; }
        public function getIsGuest() { return false; }
    });
    $theme = new \api\modules\v1\models\forum\Theme(['scenario' => 'create']);
    $theme->load(['title' => 'New discussion', 'view' => 999, 'user_id' => 77], '');
    giftCheck($theme->save() && (int)$theme->view === 0 && (int)$theme->user_id === 1, 'new theme initializes mandatory view count and keeps authenticated ownership');
    $comment = \api\modules\v1\models\forum\Comment::findOne(1);
    giftCheck($comment->toArray()['gifted_by_me'] === true, 'authenticated donor sees persisted gift state');
    Yii::$app->set('request', new \yii\web\Request());
    Yii::$app->request->setQueryParams(['q' => 'first', 'page' => '1', 'per-page' => '1']);
    $controller = new \api\modules\v1\controllers\ForumCommentController('forum-comment', Yii::$app);
    $provider = $controller->actionGifts(1);
    giftCheck($provider->getTotalCount() === 1 && $provider->getModels()[0]['username'] === 'FirstDonor', 'donor list supports server search and complete pagination count');
    Yii::$app->request->setQueryParams(['q' => 'missing']);
    giftCheck($controller->actionGifts(1)->getTotalCount() === 0, 'donor search does not leak gifts of another message');
    echo "Forum gift regression passed (SQLite). PostgreSQL/MySQL row locks require target-engine integration.\n";
} finally {
    foreach ($processes as [$process, $pipes]) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    }
    if (isset($db)) $db->close();
    if (is_file($file)) unlink($file);
}
