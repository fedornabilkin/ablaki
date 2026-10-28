<?php

/** Pipes release all initialized workers together, without guessing PHP/DB startup time. */
function workerReady(): void
{
    echo "ready\n";
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'go') throw new RuntimeException('Worker barrier closed.');
}

function releaseWorkers(array $children): void
{
    foreach ($children as list($process, $pipes)) {
        $line = trim((string)fgets($pipes[1]));
        if ($line !== 'ready') {
            $diagnostic = $line . PHP_EOL . stream_get_contents($pipes[2]);
            foreach ($children as list($child, $streams)) {
                if (is_resource($child)) proc_terminate($child);
                foreach ($streams as $stream) if (is_resource($stream)) fclose($stream);
                if (is_resource($child)) proc_close($child);
            }
            throw new RuntimeException('A database worker failed before the barrier: ' . $diagnostic);
        }
    }
    foreach ($children as list($process, $pipes)) {
        fwrite($pipes[0], "go\n");
        fclose($pipes[0]);
    }
}
