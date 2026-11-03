<?php
// Standalone process coordination check; no database or application configuration.
require __DIR__ . '/worker-barrier.php';
if (($argv[1] ?? '') === 'financial-worker') {
    require $argv[2];
    if (!\common\services\user\FinancialMaintenance::enterWriter()) exit(8);
    echo "READY\n"; fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'NESTED') exit(9);
    echo \common\services\user\FinancialMaintenance::enterWriter() ? "NESTED\n" : "DENIED\n"; fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'RELEASE') exit(10);
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if ($argv[2] === 'fail') exit(7);
    usleep((int)$argv[2]);
    $readyAt = microtime(true);
    workerReady();
    echo json_encode([$readyAt, microtime(true)]);
    exit;
}
function barrierChildren(array $delays): array
{
    $children = [];
    foreach ($delays as $delay) {
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', (string)$delay]));
        $pipes = [];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>PHP_OS_FAMILY === 'Windows']);
        if (!is_resource($process)) throw new RuntimeException('Could not start worker.');
        $children[] = [$process, $pipes];
    }
    return $children;
}
$children = barrierChildren([0, 10000, 50000, 20000]);
releaseWorkers($children);
$results = [];
foreach ($children as list($process, $pipes)) {
    $results[] = json_decode(stream_get_contents($pipes[1]), true);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Worker failed: ' . $error);
}
if (count(array_filter($results, 'is_array')) !== 4
    || min(array_column($results, 1)) < max(array_column($results, 0))) {
    throw new RuntimeException('An operation started before every worker was ready.');
}
echo "PASS fast workers wait for the last initialized worker\n";
try {
    releaseWorkers(barrierChildren([0, 'fail', 50000]));
    throw new LogicException('Failed worker was ignored.');
} catch (RuntimeException $expected) {
    if (strpos($expected->getMessage(), 'A database worker failed before the barrier:') !== 0) throw $expected;
}
echo "PASS startup failure aborts the race and closes waiting workers\n";

// Copy the actual guard to a disposable tree: never create pause markers in an application runtime.
$fixture = sys_get_temp_dir() . '/ablaki-financial-gate-' . bin2hex(random_bytes(8));
$services = $fixture . '/common/services/user';
if (!mkdir($services, 0700, true)) throw new RuntimeException('Could not create gate fixture.');
$classFile = $services . '/FinancialMaintenance.php';
if (!copy(dirname(__DIR__, 2) . '/common/services/user/FinancialMaintenance.php', $classFile)) throw new RuntimeException('Could not copy the real guard.');
require $classFile;
$directory = \common\services\user\FinancialMaintenance::directory();
if (realpath(dirname($directory, 3)) !== realpath($fixture)) throw new RuntimeException('Unsafe fixture path.');
$child = null; $pipes = []; $exclusive = null;
try {
    if (!\common\services\user\FinancialMaintenance::enterWriter()) throw new RuntimeException('Inert gate blocked a request.');
    mkdir($directory, 0700, true);
    touch($directory . '/writers.lock');
    $child = proc_open([PHP_BINARY, __FILE__, 'financial-worker', $classFile], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($child)) throw new RuntimeException('Could not start financial writer.');
    stream_set_timeout($pipes[1], 10);
    if (trim((string)fgets($pipes[1])) !== 'READY') throw new RuntimeException('Writer did not acquire its lease.');
    $exclusive = fopen($directory . '/writers.lock', 'r');
    if (flock($exclusive, LOCK_EX | LOCK_NB)) throw new RuntimeException('Migration skipped an in-flight writer.');
    touch($directory . '/paused');
    if (\common\services\user\FinancialMaintenance::enterWriter()) throw new RuntimeException('New writer bypassed maintenance.');
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
    Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
    Yii::setAlias('@frontend', dirname(__DIR__, 2) . '/frontend');
    $advertisement = new class extends \frontend\modules\advertising\widgets\ads\AdsWidget {
        public function __construct() {}
        protected function getCompany() { throw new LogicException('Paid ad reached the database during maintenance.'); }
    };
    if ($advertisement->run() !== '') throw new RuntimeException('Paid advertising was not suppressed on a read page.');
    fwrite($pipes[0], "NESTED\n"); fflush($pipes[0]);
    if (trim((string)fgets($pipes[1])) !== 'NESTED') throw new RuntimeException('An admitted transaction was interrupted.');
    fwrite($pipes[0], "RELEASE\n"); fclose($pipes[0]); unset($pipes[0]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $pipes=[];
    $exit = proc_close($child); $child=null;
    if ($exit !== 0) throw new RuntimeException('Writer failed: ' . $error);
    if (!flock($exclusive, LOCK_EX | LOCK_NB)) throw new RuntimeException('Completed writer retained a lease.');
    unlink($directory . '/paused');
    if (\common\services\user\FinancialMaintenance::enterWriter()) throw new RuntimeException('Writer bypassed exclusive migration lock.');
    if ($advertisement->run() !== '') throw new RuntimeException('Paid advertising bypassed the migration lease.');
    flock($exclusive, LOCK_UN); fclose($exclusive); $exclusive=null;
    if (!\common\services\user\FinancialMaintenance::enterWriter()) throw new RuntimeException('Writer did not resume.');
    \common\services\user\FinancialMaintenance::release();
    foreach (['v1/bonus/everyday','v1/rating/everyday','v1/orel/remove','v1/saper/start','site/registration','unknown/index'] as $route) {
        if (\common\services\user\FinancialMaintenance::readAllowed($route,'GET')) throw new RuntimeException('Unsafe GET allowed: '.$route);
    }
    foreach (['v1/user/profile','v1/history/balance','v1/orel/index','v1/forum-theme/view'] as $route) {
        if (!\common\services\user\FinancialMaintenance::readAllowed($route,'GET') || \common\services\user\FinancialMaintenance::readAllowed($route,'POST')) throw new RuntimeException('Read policy failed: '.$route);
    }
    echo "PASS maintenance drains existing writers, rejects new ones, preserves reads and resumes safely\n";
} finally {
    \common\services\user\FinancialMaintenance::release();
    if (isset($pipes[0]) && is_resource($pipes[0])) { fwrite($pipes[0], "RELEASE\n"); fclose($pipes[0]); }
    foreach ([1,2] as $i) if (isset($pipes[$i]) && is_resource($pipes[$i])) fclose($pipes[$i]);
    if (is_resource($child)) proc_close($child);
    if (is_resource($exclusive)) { flock($exclusive,LOCK_UN); fclose($exclusive); }
    foreach (['paused','writers.lock'] as $file) if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
    if (is_dir($directory)) rmdir($directory);
    if (is_dir($fixture.'/common/runtime')) rmdir($fixture.'/common/runtime');
    unlink($classFile); rmdir($services); rmdir(dirname($services)); rmdir($fixture.'/common'); rmdir($fixture);
}
