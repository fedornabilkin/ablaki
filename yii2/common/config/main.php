<?php

use common\helpers\Env;
use common\models\user\User;
use common\modules\forum\Module;
use yii\redis\Cache;

$config = [
    'bootstrap' => [\common\modules\economy\service\WalletMaintenanceBootstrap::class],
    'language' => 'ru-RU',
    'charset' => 'UTF-8',
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
            'charset' => 'utf8',
            // Each web application has its own cache; CLI migrations cannot invalidate all of them.
            // Read the current schema on each request so newly migrated tables are immediately visible.
            'enableSchemaCache' => false,
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'mailer' => [
            'class' => 'yii\swiftmailer\Mailer',
            // Keep registration functional in environments without SMTP.
            // The transport can be replaced by deployment configuration later.
            'useFileTransport' => true,
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
        'world' => ['class' => \common\modules\world\Module::class],
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
            'class' => \common\modules\games\Module::class,
        ],
        'forum' => [
            'class' => Module::class
        ],
        'craft' => [
            'class' => \common\modules\craft\Module::class,
        ],
    ],
];

if (getenv('MYSQL_DB_HOST') && getenv('MYSQL_DB_NAME')) {
    $config['components']['db'] = [
        'class' => 'yii\db\Connection',
        'dsn' => 'mysql:host=' . getenv('MYSQL_DB_HOST') . ';dbname=' . getenv('MYSQL_DB_NAME'),
        'username' => getenv('MYSQL_DB_USER'),
        'password' => getenv('MYSQL_DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'attributes' => [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'],
        'enableSchemaCache' => false,
    ];
}

foreach (glob(__DIR__ . '/components/*.php') as $file) {
    $componentName = str_replace('.php', '', basename($file));
    $config['components'][$componentName] = require $file;
}

return $config;
