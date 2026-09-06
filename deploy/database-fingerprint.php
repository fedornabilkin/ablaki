<?php
// Read via stdin in a one-off PHP container, with /web/yii2 as cwd.
// Never print a DSN, password, or exception trace to the deployment log.
define('YII_DEBUG', false);
define('YII_ENV', 'prod');
try {
    require getcwd() . '/vendor/autoload.php';
    require getcwd() . '/vendor/yiisoft/yii2/Yii.php';
    require getcwd() . '/common/config/bootstrap.php';
    require getcwd() . '/console/config/bootstrap.php';
    $app = new yii\console\Application(yii\helpers\ArrayHelper::merge(
        require getcwd() . '/common/config/main.php',
        getLocalConfig(getcwd() . '/common/config/main-local.php'),
        require getcwd() . '/console/config/main.php',
        getLocalConfig(getcwd() . '/console/config/main-local.php')
    ));
    $apiConfig = yii\helpers\ArrayHelper::merge(
        require getcwd() . '/common/config/main.php',
        getLocalConfig(getcwd() . '/common/config/main-local.php'),
        require getcwd() . '/api/config/main.php'
    );
    $apiDb = Yii::createObject($apiConfig['components']['db']);
    if ($app->db->driverName !== 'pgsql' || $apiDb->driverName !== 'pgsql' || $app->db->tablePrefix !== $apiDb->tablePrefix) {
        throw new RuntimeException('unsupported database');
    }
    $identity = $app->db->createCommand('SELECT system_identifier::text || chr(47) || current_database() FROM pg_control_system()')->queryScalar();
    if (!is_string($identity) || $identity === '') throw new RuntimeException('missing database identity');
    if ($apiDb->createCommand('SELECT system_identifier::text || chr(47) || current_database() FROM pg_control_system()')->queryScalar() !== $identity) throw new RuntimeException('API database mismatch');
    echo hash('sha256', $identity);
} catch (Throwable $error) {
    fwrite(STDERR, "Cannot verify the application PostgreSQL database. Check the configured connection and backup permissions.\n");
    exit(1);
}
