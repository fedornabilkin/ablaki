<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.10.2020
 * Time: 16:57
 */

use common\components\logger\FileTarget;

return [
    'traceLevel' => YII_DEBUG ? 3 : 0,
    'targets' => [
        [
            'class' => FileTarget::class,
            'maxLogFiles' => 5,
            'levels' => ['error', 'warning'],
            'logFile' => '@runtime/logs/app.log',
            'logVars' => [],
            'prefix' => function () {
                return '[app]';
            },
        ],
    ],
];
