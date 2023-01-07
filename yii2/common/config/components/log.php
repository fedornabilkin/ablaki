<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.10.2020
 * Time: 16:57
 */

return [
    'traceLevel' => YII_DEBUG ? 3 : 0,
    'targets' => [
        [
            'class' => 'yii\log\FileTarget',
            'levels' => ['error', 'warning'],
        ],
    ],
];
