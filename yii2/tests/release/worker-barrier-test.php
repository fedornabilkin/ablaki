<?php
// Standalone process coordination check; no database or application configuration.
require __DIR__ . '/worker-barrier.php';
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
    if ($expected->getMessage() !== 'A database worker failed before the barrier.') throw $expected;
}
echo "PASS startup failure aborts the race and closes waiting workers\n";
