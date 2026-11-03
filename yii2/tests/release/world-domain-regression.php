<?php
/** Compatible suites share the expensive initial fixture, not each other's mutations. */
require __DIR__ . '/world-economy-runtime.php';
$fixture = get_defined_vars();
foreach (['world-event-foundation.php', 'world-craft-refactor.php'] as $suite) {
    $isolation = $db->beginTransaction();
    try {
        (static function (string $suite, array $fixture): void {
            extract($fixture, EXTR_SKIP);
            require __DIR__ . '/' . $suite;
        })($suite, $fixture);
    } finally {
        $isolation->rollBack();
    }
}
echo "World shared domain regression checks passed.\n";
