<?php
require dirname(__DIR__, 2) . '/common/modules/world/domain/DevelopmentRoute.php';
use common\modules\world\domain\DevelopmentRoute;

function checkRoute(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
$empty = DevelopmentRoute::progress([], []);
checkRoute($empty['completed'] === 0 && $empty['next_stage'] === 'home', 'New residents start with settlement, without fabricated progress.');
$profile = json_decode(file_get_contents(dirname(__DIR__, 2) . '/common/modules/world/data/economy/closed-test-v1.json'), true, 512, JSON_THROW_ON_ERROR);
$facts = []; $codes = [];
foreach (DevelopmentRoute::stages() as $stage) {
    checkRoute(mb_strlen($stage['description'], 'UTF-8') <= 500, 'Stage description fits the API contract: ' . $stage['code']);
    foreach ($stage['steps'] as $step) {
        checkRoute(!isset($codes[$step['code']]), 'Unique objective: ' . $step['code']);
        $codes[$step['code']] = true;
        $facts[$step['code']] = $step['required'];
        if (str_starts_with($step['code'], 'produced:')) {
            checkRoute(isset($profile['recipes'][substr($step['code'], 9)]), 'Craft objective references a real recipe: ' . $step['code']);
        }
    }
}
$complete = DevelopmentRoute::progress($facts, ['map:mine' => '/world/nodes/42']);
checkRoute($complete['completed'] === $complete['total'] && $complete['next_stage'] === null, 'Every stage completes from server facts.');
checkRoute(isset($codes['produced:starter-carrot-seeds']) && !isset($codes['produced:world-carrot-seed']), 'Seed replenishment uses the actual recipe code, not possession of starter seeds.');
unset($facts['built:garden-bed@small-greenhouse']);
$missingChild = DevelopmentRoute::progress($facts, []);
checkRoute($missingChild['completed'] === $complete['total'] - 1 && $missingChild['next_stage'] === 'expansion', 'An ordinary garden bed cannot complete the greenhouse objective.');
unset($facts['own-source:mine-stone']); $facts['source:mine-stone'] = 100;
checkRoute(DevelopmentRoute::progress($facts, [])['next_stage'] === 'mining', 'Paid extraction is not proof of mining at home.');
checkRoute(!isset($codes['built:estate']) && !isset($codes['built:mini-storage-level-2']), 'No objectives require ownership of the public city or world.');
