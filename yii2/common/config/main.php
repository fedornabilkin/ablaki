<?php

use common\helpers\Env;
use common\models\user\User;
use common\modules\games\Module;
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
            'keyPrefix' => 'blk:cache',
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
        'exchange' => [
            'class' => \common\modules\exchange\Module::class,
        ],
        'games' => [
            'class' => Module::class,
        ],
    ],
];

if (getenv('MYSQL_DB_HOST') !== '' && getenv('MYSQL_DB_NAME') !== '') {
    $config['components']['db'] = [
        'class' => 'yii\db\Connection',
        'dsn' => 'mysql:host=' . getenv('MYSQL_DB_HOST') . ';dbname=' . getenv('MYSQL_DB_NAME'),
        'username' => getenv('MYSQL_DB_USER'),
        'password' => getenv('MYSQL_DB_PASSWORD'),
        'charset' => 'utf8',
    ];
}

foreach (glob(__DIR__ . '/components/*.php') as $file) {
    $componentName = str_replace('.php', '', basename($file));
    $config['components'][$componentName] = require $file;
}

return $config;
