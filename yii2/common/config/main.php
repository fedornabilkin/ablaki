<?php

use common\helpers\Env;
use common\models\user\User;
use yii\redis\Cache;

$config = [
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(__DIR__, 2) . '/vendor',
    'components' => [
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'pgsql:host=' . getenv('PG_DB_HOST') . ';dbname=' . getenv('PG_DB_NAME'),
            'username' => getenv('PG_DB_USER'),
            'password' => getenv('PG_DB_PASSWORD'),
//            'charset' => 'utf8',
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'redis' => [
            'class' => Cache::class,
            'keyPrefix' => 'crm:cache',
            'redis' => [
                'hostname' => Env::redisHost(),
                'port' => Env::redisPort(),
                'database' => Env::redisCacheDatabase(),
                'password' => Env::redisPassword(),
            ],
        ],
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
        'formatter' => [
            'class' => 'yii\i18n\Formatter',
            'defaultTimeZone' => 'UTC',
            'timeZone' => 'Europe/Moscow',
        ],
    ],

    'modules' => [
        'user' => [
            'class' => 'dektrium\user\Module',
            'enableFlashMessages' => false,
            'enableRegistration' => true,
            'modelMap' => [
                'User' => User::class,
            ],
        ],
    ],
];

foreach (glob(__DIR__ . '/components/*.php') as $file) {
    $componentName = str_replace('.php', '', basename($file));
    $config['components'][$componentName] = require $file;
}

return $config;
