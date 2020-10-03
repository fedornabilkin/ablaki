<?php

use fedornabilkin\binds\behaviors\SeoBehavior;
use frontend\controllers\user\WallController;
use frontend\modules\advertising\Module;

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    getLocalConfig(__DIR__ . '/../../common/config/params-local.php'),
    require __DIR__ . '/params.php',
    getLocalConfig(__DIR__ . '/params-local.php')
);

return [
    'id' => 'app-frontend',
    'name' => 'ablaki.ru',
    'language' => 'ru-RU',
//    'timeZone' => 'Europe/Moscow',

    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'frontend\controllers',
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-frontend',
            'cookieValidationKey' => 'SVK01iZH047QrU-FDE0HumgBSnqljvnc',
        ],
        'session' => [
            // this is the name of the session cookie used for login on the frontend
            'name' => 'advanced-frontend',
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'i18n' => [
            'translations' => [
                '*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages',
                ],
            ],
        ],

        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'user/wall/<login:[[^a-zA-Z0-9\-]+>' => 'user/wall',
                'games/saper/start/<id:[[^0-9]+>' => 'games/saper/start',
                'games/saper/play/<id:[[^0-9]+>' => 'games/saper/play',
            ],
        ],

        'assetManager' => [
            'appendTimestamp' => true,
        ],

        'view' => [
            'as seo' => [
                'class' => SeoBehavior::class,
            ],
            'theme' => [
                'pathMap' => [
                    '@dektrium/user/views' => '@frontend/views/user'
                ],
            ],
        ],
    ],
    'modules' => [
        'user' => [
            // following line will restrict access to admin controller from frontend application
            'as frontend' => 'dektrium\user\filters\FrontendFilter',
            'class' => \dektrium\user\Module::class,
            'controllerMap' => [
                'wall' => WallController::class
            ],
        ],
        'treemanager' => [
            'class' => 'kartik\tree\Module',
            'dataStructure' => [
                'keyAttribute' => 'id',
            ],
        ],
        'games' => [
            'class' => \common\modules\games\Module::class,
        ],
        'advertising' => [
            'class' => Module::class,
        ],
    ],
    'params' => $params,
];
