<?php

use mdm\admin\components\AccessControl;
use dektrium\user\models\User;
use mdm\admin\controllers\AssignmentController;
use dektrium\user\filters\BackendFilter;

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id' => 'app-backend',
    'name' => 'admin.ablaki.ru',
    'language' => 'ru-RU',

    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'backend\controllers',
    'bootstrap' => ['log', 'admin'],
    'modules' => [
        'user' => [
            'class' => 'dektrium\user\Module',
            // following line will restrict access to profile, recovery, registration and settings controllers from backend
            'as backend' => BackendFilter::class,
        ],
        'admin' => [
            'class' => \mdm\admin\Module::class,
            'layout' => 'left-menu',
            'defaultRoute' => 'assignment',
            'controllerMap' => [
                'assignment' => [
                    'class' => AssignmentController::class,
                    'userClassName' => User::class,
                    'idField' => 'id',
                ],
            ],
        ],
        'redirect' => [
            'class' => fedornabilkin\redirect\Module::class,
//            'frontendHost' => 'http://ablaki.ru',
        ],
        'binds' => [
            'class' => fedornabilkin\binds\Module::class,
        ],
        'treemanager' => [
            'class' => \kartik\tree\Module::class,
            'dataStructure' => [
                'keyAttribute' => 'id',
            ],
        ],
    ],
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-backend',
        ],
        'user' => [
            'identityClass' => \common\models\user\User::class,
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-backend', 'httpOnly' => true],
        ],
        'session' => [
            // this is the name of the session cookie used for login on the backend
            'name' => 'advanced-backend',
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],

        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
        'view' => [
            'theme' => [
                'pathMap' => [
                    '@app/views' => '@app/views/admin',
                ],
            ],
        ],

    ],
    'as access' => [
        'class' => AccessControl::class,
        'allowActions' => [
            'user/security/logout',
            'user/security/login',
            'user/logout',
            'user/login',
//            'debug/*',
            'site/*',
        ]
    ],
    'params' => $params,
];
