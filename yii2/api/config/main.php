<?php

use yii\web\Response;

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id' => 'api',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'api\controllers',
    'components' => [
        'response' => [
            'on beforeSend' => function ($event) {
                $event->sender->headers->add('Access-Control-Allow-Origin', '*');
                $event->sender->headers->add('Access-Control-Allow-Methods', 'GET, POST, HEAD, OPTIONS');
                $event->sender->headers->add('Access-Control-Allow-Headers', 'Content-Type, session-token, Authorization');
                $event->sender->headers->add('Access-Control-Expose-Headers', 'Content-Type, session-token');
                if (Yii::$app->request->isOptions) {
                    $event->sender->statusCode = 200;
                }
            },
            'format' => yii\web\Response::FORMAT_JSON,
            /** https://www.yiiframework.com/doc/guide/2.0/ru/rest-response-formatting */
//            'formatters' => [
//                'class' => 'yii\web\JsonResponseFormatter',
//                'prettyPrint' => YII_DEBUG,
//            ],
        ],
        'request' => [
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser'
            ]
        ],
        'user' => [
            'identityClass' => \api\models\UserIdentity::class,
            'enableSession' => false,
            'loginUrl' => null,
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-api', 'httpOnly' => true],
        ],
        'session' => [
            'name' => 'api',
        ],
//        'errorHandler' => [
//            'class' => 'api\components\ErrorHandler',
//        ],
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
            'enableStrictParsing' => true,
            'showScriptName' => false,
            'rules' => [
                'login' => 'site/login',
                'logout' => 'site/logout',
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/saper',
                    'except' => ['view'],
                    'extraPatterns' => [
                        'GET remove' => 'remove',
                        'GET start/{id}' => 'start',
                        'POST play/{id}' => 'play',
                        'POST double/{id}' => 'double',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => ['v1/orel'],
                    'except' => ['view'],
                    'extraPatterns' => [
                        'GET remove' => 'remove',
                        'POST play/{id}' => 'play',
                    ],
                ],
            ],
        ],
    ],

    'modules' => [
        'v1' => [
            'class' => \api\modules\v1\Module::class,
        ],
    ],

    'params' => $params,
];
