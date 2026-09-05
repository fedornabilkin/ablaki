<?php

// Standalone regression checks using a disposable SQLite database, never application .env.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

use common\services\user\DailyRewardService;
use yii\db\Connection;

function connection(string $file): Connection
{
    $db = new Connection(['dsn' => 'sqlite:' . $file]);
    $db->open();
    $db->createCommand('PRAGMA busy_timeout = 15000')->execute();
    return $db;
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}

if (($argv[1] ?? '') === 'worker') {
    while (microtime(true) < (float)$argv[3]) usleep(1000);
    $service = new DailyRewardService(connection($argv[2]));
    echo $service->claimCredit(2, 1) ? 'granted' : 'already';
    exit;
}

$file = tempnam(sys_get_temp_dir(), 'ablakin-rewards-');
$processes = [];
try {
    $db = connection($file);
    $db->createCommand('CREATE TABLE persone (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE,
        credit NUMERIC NOT NULL, balance NUMERIC NOT NULL, rating NUMERIC NOT NULL)')->execute();
    $db->createCommand('CREATE TABLE history_balance (id INTEGER PRIMARY KEY, user_id INTEGER,
        credit NUMERIC, balance NUMERIC, credit_up NUMERIC, balance_up NUMERIC,
        type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE history_rating (id INTEGER PRIMARY KEY, user_id INTEGER,
        rating NUMERIC, rating_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand("INSERT INTO persone VALUES (1, 1, 10, 5, 2), (2, 2, 10, 5, 2), (3, 3, 10, 5, 2)")->execute();
    $service = new DailyRewardService($db);

    check($service->claimCredit(1, 1), 'first bonus is granted');
    check(!$service->claimCredit(1, 1), 'repeated bonus is rejected');
    check((float)$db->createCommand('SELECT credit FROM persone WHERE id=1')->queryScalar() === 11.0,
        'bonus changes credit only once');
    check($service->claimRating(1, 0.01) && !$service->claimRating(1, 0.01), 'rating is independently granted once');
    check(abs((float)$db->createCommand('SELECT rating FROM persone WHERE id=1')->queryScalar() - 2.01) < 0.000001,
        'rating changes by the configured amount');

    $db->createCommand("INSERT INTO history_balance (user_id, type, created_at) VALUES (3, 'everyday', :today)",
        [':today' => time()])->execute();
    check(!$service->claimCredit(3, 1), 'existing history from the old controller prevents another bonus');

    $db->createCommand("UPDATE history_balance SET created_at=:yesterday WHERE user_id=1",
        [':yesterday' => strtotime('yesterday')])->execute();
    check($service->claimCredit(1, 1), 'previous day does not block the next reward');

    $db->createCommand("DELETE FROM history_balance WHERE user_id=3")->execute();
    $db->pdo->exec("CREATE TRIGGER fail_history BEFORE INSERT ON history_balance
        WHEN NEW.user_id = 3 BEGIN SELECT RAISE(ABORT, 'injected history failure'); END");
    try { $service->claimCredit(3, 1); throw new RuntimeException('Expected history failure'); }
    catch (\yii\db\Exception $expected) { /* The failure must escape the transaction. */ }
    check((float)$db->createCommand('SELECT credit FROM persone WHERE id=3')->queryScalar() === 10.0,
        'history failure leaves credit unchanged');
    $db->createCommand('DROP TRIGGER fail_history')->execute();

    $db->pdo->exec("CREATE TRIGGER fail_credit BEFORE UPDATE OF credit ON persone
        WHEN NEW.user_id = 3 AND NEW.credit > OLD.credit
        BEGIN SELECT RAISE(ABORT, 'injected credit failure'); END");
    try { $service->claimCredit(3, 1); throw new RuntimeException('Expected credit failure'); }
    catch (\yii\db\Exception $expected) { /* History must be rolled back as well. */ }
    check((int)$db->createCommand('SELECT COUNT(*) FROM history_balance WHERE user_id=3')->queryScalar() === 0,
        'credit failure rolls back the inserted history');
    $db->createCommand('DROP TRIGGER fail_credit')->execute();
    check($service->claimCredit(3, 1), 'failed operation can be retried successfully');

    $start = microtime(true) + 1;
    for ($i = 0; $i < 8; $i++) {
        $args = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
            '-d', 'extension=pdo_sqlite', __FILE__, 'worker', $file, (string)$start];
        $command = implode(' ', array_map('escapeshellarg', $args));
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start test worker.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $granted = 0;
    foreach ($processes as [$process, $pipes]) {
        $output = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException('Worker failed: ' . $output . $error);
        if ($output === 'granted') $granted++;
        elseif ($output !== 'already') throw new RuntimeException('Unexpected worker result: ' . $output);
    }
    $processes = [];
    check($granted === 1, 'eight concurrent requests grant exactly one bonus');
    check((int)$db->createCommand('SELECT COUNT(*) FROM history_balance WHERE user_id=2')->queryScalar() === 1,
        'concurrent requests create exactly one history row');
    check((float)$db->createCommand('SELECT credit FROM persone WHERE id=2')->queryScalar() === 11.0,
        'concurrent requests change credit exactly once');
    echo "Daily reward regression checks passed (SQLite). PostgreSQL/MySQL row locking needs its own integration run.\n";
} finally {
    foreach ($processes as [$process, $pipes]) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    }
    if (isset($db)) $db->close();
    if (is_file($file)) unlink($file);
}
