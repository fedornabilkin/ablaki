<?php

use api\models\UserIdentity;
use api\modules\v1\Module;

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/params.php'
);

$header_remove = 'header_remove';
if (function_exists($header_remove)) {
    $header_remove('X-Powered-By');
}

return [
    'id' => 'api',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log', 'v1', 'exchange', 'games'],
    'controllerNamespace' => 'api\controllers',
    'components' => [
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
            /** https://www.yiiframework.com/doc/guide/2.0/ru/rest-response-formatting */
//            'formatters' => [
//                'class' => 'yii\web\JsonResponseFormatter',
//                'prettyPrint' => YII_DEBUG,
//            ],
        ],
        'request' => [
//            'enableCsrfValidation' => false,
            'cookieValidationKey' => 'JDaZ5GOpnlg94q38dzWDLKjPR5rQXXlF',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser'
            ]
        ],
        'user' => [
            'identityClass' => UserIdentity::class,
            'enableSession' => false,
            'loginUrl' => null,
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-api', 'httpOnly' => true],
        ],
//        'session' => [
//            'name' => 'api',
//        ],
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
                'registration' => 'site/registration',
            ],
        ],
    ],

    'modules' => [
        'v1' => [
            'class' => Module::class,
        ],
    ],

    'params' => $params,
];
