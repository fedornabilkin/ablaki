<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 09.01.2022
 * Time: 17:41
 */

use yii\rest\UrlRule;

return [
    [
        'class' => UrlRule::class,
        'controller' => ['v1/exchange'],
        'pluralize' => false,
        'except' => ['view'],
        'extraPatterns' => [
            'GET remove' => 'remove',
            'GET my' => 'my',
            'GET history' => 'history',
            'GET available-count' => 'available-count',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/transfer'],
        'pluralize' => false,
        'extraPatterns' => [
            'GET history' => 'history',
        ],
    ],
];
