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
        'controller' => ['v1/user'],
        'only' => ['wall', 'data', 'profile'],
        'extraPatterns' => [
            'GET data' => 'data',
            'GET profile' => 'profile',
            'GET wall/<login:\w+>' => 'wall',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/history'],
        'only' => ['index', 'create', 'balance', 'balance-type', 'rating', 'rating-type'],
        'pluralize' => false,
        'extraPatterns' => [
            'GET balance' => 'balance',
            'GET balance-type' => 'balance-type',
            'GET rating' => 'rating',
            'GET rating-type' => 'rating-type',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/rating'],
        'pluralize' => false,
        'only' => ['everyday'],
        'extraPatterns' => [
            'GET everyday' => 'everyday',
        ],
    ],
    [
        'class' => UrlRule::class,
        'controller' => ['v1/bonus'],
        'pluralize' => false,
        'only' => ['everyday'],
        'extraPatterns' => [
            'GET everyday' => 'everyday',
        ],
    ],
//    [
//        'class' => UrlRule::class,
//        'controller' => 'v1/catalog',
//        'only' => [
//            'pay-types',
//            'order-statuses',
//            'product-statuses',
//            'pay-statuses',
//            'tare-types',
//            'customer-requests',
//            'change-reasons'
//        ],
//        'extraPatterns' => [
//            'GET pay-types' => 'pay-types',
//            'GET order-statuses' => 'order-statuses',
//            'GET product-statuses' => 'product-statuses',
//            'GET pay-statuses' => 'pay-statuses',
//            'GET tare-types' => 'tare-types',
//            'GET customer-requests' => 'customer-requests',
//            'GET change-reasons' => 'change-reasons',
//        ],
//    ],
//    [
//        'class' => UrlRule::class,
//        'controller' => 'v1/order',
//        'only' => [
//            'create',
//            'view',
//            'view-fpa',
//            'index',
//            'get',
//            'cancel',
//            'status',
//            'payment',
//            'send-code',
//            'ready-list',
//            'send-file',
//            'by-promos',
//        ],
//        'extraPatterns' => [
//            'POST get/<id:\d+>' => 'view',
//            'GET view-fpa/<id:\d+>' => 'view-fpa',
//            'POST get' => 'index',
//            'POST cancel' => 'cancel',
//            'POST status' => 'status',
//            'POST payment' => 'payment',
//            'POST <orderId:\d+>/sendcode' => 'send-code',
//            'GET readylist/<pfm:[0-9a-zA-Z\-]+>' => 'ready-list',
//            'POST <orderId:\d+>/sendfile' => 'send-file',
//            'GET by-promos' => 'by-promos',
//        ],
//    ],
];
