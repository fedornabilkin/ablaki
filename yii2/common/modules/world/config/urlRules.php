<?php
return array_merge([
    'GET v1/world/objects/<id:\d+>/budget' => 'world/account-api/view',
    'POST v1/world/objects/<id:\d+>/budget' => 'world/account-api/create',
    'POST v1/world/objects/<id:\d+>/gather' => 'world/supply-api/create',
    'GET v1/world/objects' => 'world/object-api/index',
    'GET v1/world/objects/<id:\d+>' => 'world/object-api/view',
    'GET v1/world/templates' => 'world/template-api/index',
    'GET v1/world/events' => 'world/event-api/index',
    'GET v1/world/builds' => 'world/build-api/index',
    'GET v1/world/builds/<id:\d+>' => 'world/build-api/view',
    'POST v1/world/builds' => 'world/build-api/create',
    'POST v1/world/builds/<id:\d+>/<action:start|heartbeat|stop|cancel>' => 'world/build-api/<action>',
    'GET v1/world/start' => 'world/start-api/index',
    'POST v1/world/start' => 'world/start-api/create',
    'OPTIONS v1/world/<path:.*>' => 'world/object-api/options',
    'craft/<controller:[\w-]+>/<action:[\w-]+>' => 'world/craft/<controller>/<action>',
    'craft/<controller:[\w-]+>' => 'world/craft/<controller>/index',
], require __DIR__ . '/legacyRoutes.php');
