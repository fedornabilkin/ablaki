<?php
// Extends the guarded world fixture and reuses its failure injection and command checks.
require __DIR__ . '/world-command-pipeline.php';

use common\modules\world\models\Command;
use common\modules\world\modules\craft\models\domain\{CraftStorage, Crafting};
use yii\db\Query;

function craftOperationSnapshot($db): array
{
    $state = pipelineSnapshot($db);
    foreach ([
        'craft_inventory', 'craft_storage', 'craft_equipment_instance', 'craft_tool_wear',
        'craft_known', 'craft_skill', 'craft_history', 'craft_event', 'craft_command',
        'inventory_movement', 'world_economy_usage', 'world_economy_fact', 'world_economy_fact_event',
    ] as $table) {
        $keys = $db->schema->getTableSchema($table)->primaryKey;
        $state[$table] = (new Query())->from($table)->orderBy(array_fill_keys($keys, SORT_ASC))->all($db);
    }
    return $state;
}

$craftStore = new CraftStorage($db);
$craftStore->workspaceNodeId = $siteId;
$brickRecipe = (new Query())->from('craft_recipe')->where(['code' => 'classic-brick'])->one($db);
$brick = (new Query())->from('craft_item')->where(['id' => $brickRecipe['item_id']])->one($db);
$hammerRecipe = (new Query())->from('craft_recipe')->where(['code' => 'classic-hammer'])->one($db);
Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.craft-stock', [], static function ($operation) use ($db, $craftStore, $freshUser, $hammerRecipe) {
    $craftStore->operationId = $operation;
    foreach (['classic-clay' => 10, 'classic-sand' => 10, 'classic-hammer' => 1] as $code => $quantity) {
        $item = (new Query())->from('craft_item')->where(['code' => $code])->one($db);
        $craftStore->move($freshUser, $item, $quantity);
    }
    $db->createCommand()->upsert('craft_known', [
        'user_id' => $freshUser, 'recipe_id' => $hammerRecipe['id'], 'quantity' => 1,
    ], false)->execute();
    return ['prepared' => true];
});
$craftEngine = new Crafting($craftStore);
$payload = ['id' => (int)$brickRecipe['id'], 'quantity' => 1];
foreach (['game_operation', 'inventory_movement', 'craft_command'] as $table) {
    $before = craftOperationSnapshot($db);
    $key = bin2hex(random_bytes(16));
    rejectWorldWrite($db, $table, static fn() => $craftEngine->command($freshUser, $key, 'craft', $payload));
    economyCheck(craftOperationSnapshot($db) === $before, $table . ' failure rolls back craft materials, output, wear, XP, quota and history');
    economyCheck($craftStore->protectedInstances === [], 'failed craft releases its equipment selection in the reused service');

    $quantities = $craftStore->quantities($freshUser);
    $result = $craftEngine->command($freshUser, $key, 'craft', $payload);
    $after = craftOperationSnapshot($db);
    economyCheck(!$result['replayed']
        && $craftStore->quantities($freshUser)[$brick['id']] === ($quantities[$brick['id']] ?? 0) + (int)$brickRecipe['output_quantity'],
        'same key succeeds after journal failure and grants exactly one recipe batch');
    $operation = (new Query())->from('game_operation')->where(['id' => $craftStore->operationId])->one($db);
    economyCheck($operation['type'] === 'craft.craft'
        && (new Query())->from('inventory_movement')->where(['operation_id' => $operation['id']])->count('*', $db) > 0,
        'movements and recipe completion reuse one operation and its existing type');
    economyCheck($craftEngine->command($freshUser, $key, 'craft', $payload)['replayed']
        && craftOperationSnapshot($db) === $after, 'craft replay never repeats materials, XP, wear or quota');
}

$before = craftOperationSnapshot($db);
try {
    $craftEngine->command($freshUser, bin2hex(random_bytes(16)), 'craft', ['id' => (int)$brickRecipe['id'], 'quantity' => '1']);
    throw new LogicException('String quantity accepted.');
} catch (yii\web\UnprocessableEntityHttpException $error) {
    economyCheck($error->statusCode === 422 && craftOperationSnapshot($db) === $before,
        'framework adapter preserves validation status and performs no writes');
}

// Exercise the same placement plan through the canonical grant writer on a full backpack.
$before = craftOperationSnapshot($db);
try {
    Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.overflow', [], static function ($operation) use ($craftStore, $freshUser, $brick) {
        $craftStore->operationId = $operation;
        $craftStore->move($freshUser, $brick, (int)$brick['stack_size'] * 51);
        return ['unexpected' => true];
    });
    throw new LogicException('Overflow accepted.');
} catch (yii\web\ConflictHttpException $expected) {
    economyCheck(craftOperationSnapshot($db) === $before, 'insufficient output capacity rolls back every partial grant and movement');
}
echo 'World craft refactoring checks passed.' . PHP_EOL;
