<?php
// Projection contracts only: an isolated in-memory fixture, no gameplay or production database.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
$app = new yii\console\Application(['id' => 'player-read-test', 'basePath' => dirname(__DIR__, 2),
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:']]]);
$db = $app->db;
foreach ([
    'craft_category (id INTEGER PRIMARY KEY, name TEXT)',
    'craft_skill (user_id INTEGER, category_id INTEGER, experience INTEGER)',
    'game_actor (id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT)',
    'profession (id INTEGER PRIMARY KEY, name TEXT)',
    'actor_profession (actor_id INTEGER, profession_id INTEGER, level INTEGER, xp INTEGER)',
    'world_node (id INTEGER PRIMARY KEY, name TEXT)',
    'world_mining_worker (user_id INTEGER, node_id INTEGER, last_seen_at INTEGER)',
    'world_build (id INTEGER PRIMARY KEY, node_id INTEGER, status TEXT)',
    'world_build_work (id INTEGER PRIMARY KEY, build_id INTEGER, ended_at INTEGER)',
    'world_builder (user_id INTEGER, work_id INTEGER)',
] as $schema) $db->createCommand('CREATE TABLE ' . $schema)->execute();
$db->createCommand("INSERT INTO craft_category VALUES (1, 'Дерево')")->execute();
$db->createCommand('INSERT INTO craft_skill VALUES (1,1,150),(2,1,900)')->execute();
$db->createCommand("INSERT INTO game_actor VALUES (1,1,'player'),(2,2,'player'),(3,1,'npc')")->execute();
$db->createCommand("INSERT INTO profession VALUES (1,'Исследователь')")->execute();
$db->createCommand('INSERT INTO actor_profession VALUES (1,1,2,50),(2,1,8,900),(3,1,99,999)')->execute();
$db->createCommand("INSERT INTO world_node VALUES (10,'Шахта')")->execute();
$db->createCommand('INSERT INTO world_mining_worker VALUES (1,10,1000)')->execute();
$db->createCommand("INSERT INTO world_build VALUES (1,10,'constructing'),(2,20,'constructing')")->execute();
$db->createCommand('INSERT INTO world_build_work VALUES (1,1,1000),(2,2,1000)')->execute();
$db->createCommand('INSERT INTO world_builder VALUES (1,1),(2,2)')->execute();
$reader = new common\modules\world\models\domain\WorldPlayerRead($db);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$state = $reader->overview(1);
$check($state['progression']['craft'] === [['name' => 'Дерево', 'level' => 2, 'xp' => 150]], 'craft belongs to the requested player');
$check($state['progression']['professions'] === [['name' => 'Исследователь', 'level' => 2, 'xp' => 50]], 'NPC and other players are excluded');
$check($state['progression']['extraction_work']['node_id'] === 10, 'mining projection is nested in progression');
$check($state['construction']['active'] === null && $reader->activeConstruction(2) === null, 'ended work is not active');
$check($reader->overview(3)['construction']['active'] === null && $reader->progression(3)['craft'] === [], 'new players have explicit empty and null states');
$db->createCommand('UPDATE world_build_work SET ended_at=1001 WHERE id=1')->execute();
$check($reader->activeConstruction(1) === null, 'completed work is not returned as active');
echo "World player read: $checks checks passed.\n";
