<?php
// No Composer bootstrap, Yii, PDO, application configuration or database.
$domain = dirname(__DIR__, 2) . '/common/modules/world/modules/craft/domain/';
foreach (['InventoryPlacement', 'BackpackLayout', 'CraftExperience', 'CraftCommandInput'] as $name) {
    require $domain . $name . '.php';
}

use common\modules\world\modules\craft\domain\{InventoryPlacement, BackpackLayout, CraftExperience, CraftCommandInput};

function checkCraftDomain(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
function rejectCraftInput(string $action, array $payload, string $message): void
{
    try {
        CraftCommandInput::parse(7, 'domain-test-key-0001', $action, $payload);
    } catch (InvalidArgumentException $error) {
        checkCraftDomain($error->getMessage() === $message, 'input rejection: ' . $message);
        return;
    }
    throw new RuntimeException('Invalid command accepted: ' . $action);
}

$rows = [
    ['id' => 10, 'item_id' => 1, 'item_quantity' => 8, 'slot' => 3],
    ['id' => 11, 'item_id' => 2, 'item_quantity' => 2, 'slot' => 1],
    ['id' => 12, 'item_id' => 1, 'item_quantity' => 10, 'slot' => 4],
];
$before = $rows;
$plan = InventoryPlacement::plan($rows, 4, 1, 17, 10);
checkCraftDomain($plan === ['grant' => [
    ['inventory_id' => 10, 'position' => 3, 'quantity' => 2],
    ['inventory_id' => null, 'position' => 2, 'quantity' => 10],
], 'missing_quantity' => 5] && $rows === $before, 'fill existing stacks before gaps without mutating the snapshot');
checkCraftDomain(InventoryPlacement::plan($rows, 4, 1, 0, 10)['grant'] === [], 'zero output leaves all stacks unchanged');
$separate = InventoryPlacement::plan($rows, 4, 1, 11, 10, false);
checkCraftDomain($separate['grant'] === [['inventory_id' => null, 'position' => 2, 'quantity' => 10]]
    && $separate['missing_quantity'] === 1, 'independent harvest batches never merge into older stacks');
$consumed = [['id' => 8, 'item_id' => 2, 'item_quantity' => 0, 'slot' => 1]];
checkCraftDomain(InventoryPlacement::plan($consumed, 1, 1, 2, 10)['grant'] === [
    ['inventory_id' => null, 'position' => 1, 'quantity' => 2],
], 'craft output can use a slot freed by consumed ingredients');
$overflow = [['id' => 8, 'item_id' => 1, 'item_quantity' => 2, 'slot' => 9]];
checkCraftDomain(InventoryPlacement::plan($overflow, 1, 1, 2, 10)['grant'][0]['inventory_id'] === null,
    'expired overflow stacks do not receive new output');
$chests = InventoryPlacement::plan([], 2, 1, 3, 1);
checkCraftDomain(count($chests['grant']) === 2 && $chests['missing_quantity'] === 1, 'portable chests occupy separate cells');

mt_srand(813);
for ($case = 0; $case < 400; $case++) {
    $capacity = mt_rand(0, 10);
    $limit = mt_rand(1, 30);
    $quantity = mt_rand(0, 150);
    $rows = [];
    for ($position = 1; $position <= $capacity + 2; $position++) {
        if (mt_rand(0, 1)) {
            $rows[] = ['id' => $position, 'slot' => $position, 'item_id' => mt_rand(1, 2), 'item_quantity' => mt_rand(1, $limit + 2)];
        }
    }
    $before = $rows;
    $merge = (bool)mt_rand(0, 1);
    $plan = InventoryPlacement::plan($rows, $capacity, 1, $quantity, $limit, $merge);
    if ($rows !== $before || array_sum(array_column($plan['grant'], 'quantity')) + $plan['missing_quantity'] !== $quantity) {
        throw new RuntimeException('Placement lost items or mutated input.');
    }
    $positions = array_column($rows, null, 'slot');
    $freeSpace = 0;
    for ($position = 1; $position <= $capacity; $position++) {
        $row = $positions[$position] ?? null;
        $freeSpace += !$row ? $limit : ($merge && $row['item_id'] === 1 ? max(0, $limit - $row['item_quantity']) : 0);
    }
    if ($plan['missing_quantity'] !== max(0, $quantity - $freeSpace)) {
        throw new RuntimeException('Placement does not use exactly the available capacity.');
    }
    foreach ($plan['grant'] as $grant) {
        $position = $grant['position'];
        $row = $positions[$position] ?? null;
        if ($position < 1 || $position > $capacity || $grant['quantity'] < 1
            || ($row && ($grant['inventory_id'] !== $row['id'] || $row['item_id'] !== 1))
            || (!$row && $grant['inventory_id'] !== null)
            || ($row['item_quantity'] ?? 0) + $grant['quantity'] > $limit) {
            throw new RuntimeException('Invalid output allocation.');
        }
        $positions[$position] = ['id' => $grant['inventory_id'], 'slot' => $position, 'item_id' => 1,
            'item_quantity' => ($row['item_quantity'] ?? 0) + $grant['quantity']];
    }
}
checkCraftDomain(true, '400 deterministic inventories conserve quantities and obey capacity, item and batch boundaries');

$capacity = BackpackLayout::capacity(null, [
    ['slots' => 10, 'expires_at' => 100],
    ['slots' => 5, 'expires_at' => 101],
    ['slots' => 40, 'expires_at' => 110],
], 100);
checkCraftDomain($capacity === ['permanent_slots' => 20, 'active_slots' => 50, 'slots_expire_at' => 101],
    'lease expiry is exclusive and capacity remains capped');
$rows = [
    ['id' => 1, 'item_id' => 1, 'item_quantity' => 8, 'slot' => 1],
    ['id' => 2, 'item_id' => 2, 'item_quantity' => 3, 'slot' => 1],
    ['id' => 3, 'item_id' => 3, 'item_quantity' => 5, 'slot' => 5],
];
$projected = BackpackLayout::project($rows, 2);
checkCraftDomain(array_column($projected, 'slot') === [1, 2, 5]
    && array_column($projected, 'id') === [1, 2, 3], 'duplicate positions refill gaps and preserve overflow identities');
checkCraftDomain(BackpackLayout::project($projected, 2) === $projected, 'layout projection is idempotent');
$quote = BackpackLayout::purchaseQuote(20, 3, 10);
checkCraftDomain($quote['total_price'] === 60 && $quote['capacity_after'] === 23
    && BackpackLayout::purchaseQuote(20, 1, 10)['total_price'] + BackpackLayout::purchaseQuote(21, 2, 10)['total_price'] === 60,
    'batch slot price equals successive individual purchases');
try {
    BackpackLayout::purchaseQuote(49, 2, 10);
    throw new RuntimeException('Capacity limit bypassed.');
} catch (DomainException $expected) {
    checkCraftDomain($expected->getMessage() === 'Все постоянные слоты уже открыты.', 'slot limit retains its domain message');
}

checkCraftDomain(CraftExperience::level(0) === 1 && CraftExperience::level(99) === 1
    && CraftExperience::level(100) === 2 && CraftExperience::level(9900) === 100, 'skill levels keep the existing thresholds');
checkCraftDomain(CraftExperience::add(9890, 30) === 9900
    && CraftExperience::recipeAward(20, 3, 7500) === 45
    && CraftExperience::recipeAward(1, 1, 1) === 1
    && CraftExperience::recipeAward(0, 10, 10000) === 0, 'XP rounding, minimum award and maximum skill are preserved');

$vectors = [
    ['craft', ['id' => 1, 'quantity' => 2], '["craft",1,2]'],
    ['transfer', ['id' => 8, 'quantity' => 4, 'slot_id' => 12, 'container_id' => 3, 'position' => 5], '["transfer",8,4,12,{"container_id":3,"position":5}]'],
    ['buy_slots', ['unit_price' => 10], '["buy_slots",0,1,{"unit_price":10}]'],
    ['buy_slots', ['quantity' => 2, 'unit_price' => 10, 'total_price' => 30], '["buy_slots",0,2,{"unit_price":10},{"total_price":30}]'],
    ['repair', ['id' => 5, 'slot_id' => 10], '["repair",5,1,10]'],
    ['merge', ['id' => 7, 'slot_id' => 9, 'target_slot_id' => 10], '["merge",7,1,9,10]'],
];
foreach ($vectors as [$action, $payload, $identity]) {
    $input = CraftCommandInput::parse(7, 'domain-test-key-0001', $action, $payload);
    checkCraftDomain($input->fingerprint === hash('sha256', $identity), $action . ' fingerprint matches the legacy receipt format');
}
$normal = CraftCommandInput::parse(7, 'domain-test-key-0001', 'starter', []);
$nullable = CraftCommandInput::parse(7, 'domain-test-key-0001', 'starter', ['id' => null, 'container_id' => null, 'position' => null, 'unit_price' => null, 'ignored' => true]);
checkCraftDomain($normal->fingerprint === $nullable->fingerprint, 'legacy null defaults and ignored fields do not change fingerprints');
foreach ([null, 1.0, '1', true, 0, 101] as $quantity) {
    rejectCraftInput('craft', ['quantity' => $quantity], 'Количество должно быть целым от 1 до 100.');
}
rejectCraftInput('repair', [], 'Выберите сундук для ремонта.');
rejectCraftInput('merge', ['slot_id' => 1, 'target_slot_id' => 1], 'Выберите два разных слота для объединения.');
rejectCraftInput('transfer', ['slot_id' => 1, 'position' => 0], 'Выберите исходный предмет и целевой слот.');
rejectCraftInput('buy_slots', ['unit_price' => 10, 'total_price' => null], 'Подтвердите полную стоимость слотов.');
rejectCraftInput('craft', ['total_price' => null], 'Стоимость не поддерживается этой операцией.');
checkCraftDomain(!class_exists('Yii', false), 'craft domain calculations run without loading Yii');
echo 'Framework-independent craft domain checks passed.' . PHP_EOL;
