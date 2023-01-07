<?php

$yiiDebug = getenv('YII_DEBUG') ?: false;
$yiiEnv = getenv('YII_ENV') ?: 'prod';

defined('YII_DEBUG') or define('YII_DEBUG', $yiiDebug);
defined('YII_ENV') or define('YII_ENV', $yiiEnv);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../../common/config/bootstrap.php';
require __DIR__ . '/../config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../../common/config/main.php',
    getLocalConfig(__DIR__ . '/../../common/config/main-local.php'),
    require __DIR__ . '/../config/main.php',
    getLocalConfig(__DIR__ . '/../config/main-local.php')
);

(new yii\web\Application($config))->run();
