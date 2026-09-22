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
        if (trim((string)fgets($pipes[1])) !== 'ready') {
            foreach ($children as list($child, $streams)) {
                if (is_resource($child)) proc_terminate($child);
                foreach ($streams as $stream) if (is_resource($stream)) fclose($stream);
                if (is_resource($child)) proc_close($child);
            }
            throw new RuntimeException('A database worker failed before the barrier.');
        }
    }
    foreach ($children as list($process, $pipes)) {
        fwrite($pipes[0], "go\n");
        fclose($pipes[0]);
    }
}
