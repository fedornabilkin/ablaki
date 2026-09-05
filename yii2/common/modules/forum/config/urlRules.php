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
        'controller' => ['v1/forum-theme'],
        'pluralize' => false,
        'extraPatterns' => [
            'GET my' => 'my',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/forum-comment'],
        'pluralize' => false,
        'extraPatterns' => [
            'GET my' => 'my',
            'POST <id:\d+>/gift' => 'gift',
            'GET <id:\d+>/gifts' => 'gifts',
            'OPTIONS <id:\d+>/gift' => 'options',
            'OPTIONS <id:\d+>/gifts' => 'options',
        ],
    ],
];
