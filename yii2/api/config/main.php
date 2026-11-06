<?php

use api\modules\v1\models\User;
use api\modules\v1\Module;

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/params.php'
);

$header_remove = 'header_remove';
if (function_exists($header_remove)) {
    $header_remove('X-Powered-By');
}

$cfg = [
    'id' => 'api',
    'container' => [
        'definitions' => [
            yii\rest\Serializer::class => api\components\ListSerializer::class,
        ],
    ],
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log', 'v1', 'exchange', 'games', 'forum', 'world', \common\services\user\ActivityBootstrap::class],
    'controllerNamespace' => 'api\controllers',
    'components' => [
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
            'charset' => 'UTF-8',
            /** https://www.yiiframework.com/doc/guide/2.0/ru/rest-response-formatting */
//            'formatters' => [
//                'class' => 'yii\web\JsonResponseFormatter',
//                'prettyPrint' => YII_DEBUG,
//            ],
        ],
        'request' => [
            // TLS terminates at the host nginx before the published Docker API port.
            // Trust only its observed peer and protocol/client-IP headers, never forwarded hosts.
            'trustedHosts' => [
                '94.250.251.94' => ['X-Forwarded-Proto', 'X-Forwarded-For'],
            ],
//            'enableCsrfValidation' => false,
            'cookieValidationKey' => 'JDaZ5GOpnlg94q38dzWDLKjPR5rQXXlF',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser'
            ]
        ],
        'user' => [
            'identityClass' => User::class,
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
                'auth/telegram/<action:config|identity|start|status|complete|cancel|unlink>' => 'telegram-auth/<action>',
                'auth/telegram/bot/<action:register|prepare|confirm>' => 'telegram-auth/bot-<action>',
                'login' => 'site/login',
                'login-key/<key:[\w\-]+>' => 'site/login-key',
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

$cfg['modules']['user']['modelMap']['User'] = User::class;
$cfg['modules']['user']['enableConfirmation'] = false;
$cfg['modules']['user']['enableUnconfirmedLogin'] = true;
$cfg['modules']['user']['mailer'] = ['class' => \api\components\SilentMailer::class];

return $cfg;
