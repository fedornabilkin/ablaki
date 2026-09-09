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
        'controller' => ['v1/items'],
        'pluralize' => false,
        'extraPatterns' => [
//            'GET my' => 'my',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/recipes'],
        'pluralize' => false,
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/inventory'],
        'pluralize' => false,
    ],
];
