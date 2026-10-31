<?php
// Inspect the real API route configuration without opening a database or executing actions.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
$api = require dirname(__DIR__, 2) . '/api/config/main.php';
$app = new yii\web\Application([
    'id' => 'api-route-catalog', 'basePath' => dirname(__DIR__, 2), 'controllerNamespace' => 'api\\controllers',
    'bootstrap' => ['v1', 'exchange', 'games', 'forum', 'world'],
    'modules' => [
        'v1' => ['class' => api\modules\v1\Module::class],
        'exchange' => ['class' => common\modules\exchange\Module::class],
        'games' => ['class' => common\modules\games\Module::class],
        'forum' => ['class' => common\modules\forum\Module::class],
        'world' => ['class' => common\modules\world\Module::class],
    ],
    'components' => [
        'request' => ['cookieValidationKey' => 'route-test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
        'urlManager' => $api['components']['urlManager'],
        'cache' => ['class' => yii\caching\ArrayCache::class],
    ],
]);
function routeLeaves($rules): array {
    $leaves = [];
    foreach ($rules as $rule) {
        if (is_array($rule)) { $leaves = array_merge($leaves, routeLeaves($rule)); continue; }
        if ($rule instanceof yii\web\CompositeUrlRule) {
            $property = new ReflectionProperty(yii\web\CompositeUrlRule::class, 'rules');
            $property->setAccessible(true);
            $leaves = array_merge($leaves, routeLeaves($property->getValue($rule)));
        } else { $leaves[] = $rule; }
    }
    return $leaves;
}
$rows = []; $missing = []; $duplicates = []; $seen = [];
foreach (routeLeaves($app->urlManager->rules) as $rule) {
    // Server-rendered craft navigation has dynamic controllers, not JSON API operations.
    if (strpos($rule->route, '<') !== false) continue;
    $resolved = $app->createController($rule->route);
    $action = null; $error = null;
    try { $action = $resolved ? $resolved[0]->createAction($resolved[1]) : null; }
    catch (Throwable $failure) { $error = $failure->getMessage(); }
    $row = ['methods' => $rule->verb ?: ['*'], 'pattern' => $rule->name, 'route' => $rule->route,
        'handler' => $action ? get_class($action->controller) . '::' . ($action instanceof yii\base\InlineAction ? $action->actionMethod : get_class($action)) : null];
    $rows[] = $row;
    if (!$action) $missing[] = $row + ['error' => $error];
    foreach ($row['methods'] as $method) {
        $key = $method . ' ' . $rule->pattern;
        if (isset($seen[$key])) $duplicates[] = [$seen[$key], $row];
        $seen[$key] = $row;
    }
}
$result = ['routes' => $rows, 'missing_actions' => $missing, 'duplicate_patterns' => $duplicates];
if (in_array('--json', $argv, true)) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
else {
    echo count($rows) . ' route rules; ' . count($missing) . ' missing actions; ' . count($duplicates) . " duplicate patterns\n";
    foreach ($missing as $row) echo 'MISSING ' . implode(',', $row['methods']) . ' ' . $row['pattern'] . ' -> ' . $row['route'] . PHP_EOL;
    foreach ($duplicates as $pair) echo 'DUPLICATE ' . $pair[1]['pattern'] . ' -> ' . $pair[1]['route'] . PHP_EOL;
}
exit($missing || $duplicates ? 1 : 0);
