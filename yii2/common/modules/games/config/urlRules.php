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
        'controller' => 'v1/saper',
        'pluralize' => false,
        'except' => ['view', 'update'],
        'extraPatterns' => [
            'GET remove' => 'remove',
            'GET start/{id}' => 'start',
            'POST play/{id}' => 'play',
            'POST double/{id}' => 'double',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/orel'],
        'pluralize' => false,
        'except' => ['view', 'update'],
        'extraPatterns' => [
            'GET remove' => 'remove',
            'GET my' => 'my',
            'GET history' => 'history',
            'GET kon-count' => 'kon-count',
            'POST play/{id}' => 'play',
        ],
    ],
];
