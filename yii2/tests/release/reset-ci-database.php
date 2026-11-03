<?php
/** Reset only the dedicated loopback CI fixture between independent suites. */
$dsn = getenv('ABL_TEST_DSN');
if (!is_string($dsn) || !preg_match('/^(mysql:host=127\.0\.0\.1;port=3306|pgsql:host=127\.0\.0\.1;port=5432);dbname=ablakin_ci$/D', $dsn)) {
    throw new RuntimeException('Reset requires the dedicated loopback ablakin_ci database.');
}
$pdo = new PDO($dsn, 'ablakin_ci', 'ci-only-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (str_starts_with($dsn, 'mysql:')) {
    $views = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'VIEW\'')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($views as $view) $pdo->exec('DROP VIEW `' . str_replace('`', '``', $view) . '`');
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
} else {
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) $pdo->exec('DROP TABLE IF EXISTS public."' . str_replace('"', '""', $table) . '" CASCADE');
}
echo "PASS dedicated CI database reset between independent suites\n";
