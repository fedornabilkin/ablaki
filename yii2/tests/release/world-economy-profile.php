<?php
// Pure profile/CLI audit. No database, live configuration or game writes.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\models\domain\EconomyOperatingCosts;
use common\modules\world\models\domain\EconomyScenario;
use common\modules\world\models\domain\EconomyBootstrapBound;
use common\modules\world\modules\craft\models\domain\EquipmentExposure;

function profileCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
function rejectsProfile(array $data, string $message): void
{
    try { EconomyProfile::report($data); }
    catch (InvalidArgumentException $exception) { profileCheck(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$profile = EconomyProfile::load();
$original = serialize($profile);
$report = EconomyProfile::report($profile);
profileCheck($report['counts'] === ['items' => 57, 'objects' => 23, 'recipes' => 44, 'actions' => 14], 'candidate contains existing catalog and concrete additions');
profileCheck($report['applied'] === false && $report['effective_from'] === null, 'audit does not activate candidate');
profileCheck($report['starter_credits_cr'] === '250.0000' && $profile['starter']['credit_source'] === 'existing_transfer', 'start uses existing transfer, without minting');
profileCheck($report['daily_grant'] === ['actual_credits_cr' => '0.0000', 'reference_value_cr' => '36.0000', 'sale_value_before_limits_cr' => '18.0000'], 'natural supply including sand and clay is distinct from actual credits');
profileCheck($profile['source_equipment']['drilling-rig'] === ['source' => 'mine', 'output_multiplier' => 5, 'reward_multiplier' => 3, 'replaces_tools' => ['world-stone-pickaxe', 'classic-pickaxe']], 'working drill replaces picks and publishes fivefold output and triple wages');
foreach ([0, 21, 2.5, '3'] as $invalidMultiplier) {
    $invalidDrill = $profile; $invalidDrill['source_equipment']['drilling-rig']['reward_multiplier'] = $invalidMultiplier;
    rejectsProfile($invalidDrill, 'drill multiplier rejects non-integer or out-of-range values');
}
$invalidDrill = $profile; $invalidDrill['source_equipment']['drilling-rig']['replaces_tools'] = ['classic-stone'];
rejectsProfile($invalidDrill, 'drill cannot replace raw materials instead of tools');
profileCheck($report['first_day_objects_only_cr'] === '140.0000', 'object price subtotal excludes materials and later income');
profileCheck($profile['objects']['starter-shelter']['price_cr'] === '20.0000' && $profile['objects']['house']['price_cr'] === '80.0000', 'shelter and house have the requested twenty/eighty prices');
profileCheck(\common\modules\world\modules\economy\value\Money::parse($profile['objects']['house']['price_cr'])->compare(\common\modules\world\modules\economy\value\Money::parse($profile['objects']['starter-shelter']['price_cr'])) > 0
    && $profile['objects']['house']['materials']['classic-plank'] > $profile['objects']['starter-shelter']['materials']['classic-plank']
    && $profile['objects']['house']['materials']['classic-rope'] >= $profile['objects']['starter-shelter']['materials']['classic-rope'], 'house costs more than shelter and needs more building materials');
profileCheck($report['reachability']['blocked_targets'] === [] && $report['reachability']['blocked_recipes'] === [], 'all target recipes have a physical bootstrap path');
profileCheck($report['recipe_margins']['classic-plank']['batch_sale_value_cr'] === '3.0000' && $report['recipe_margins']['classic-plank']['margin_before_wear_cr'] === '1.0000', 'plank batch revenue is two unit prices');
profileCheck($report['recipe_margins']['classic-stick']['quantity'] === 4 && $report['recipe_margins']['classic-stick']['margin_before_wear_cr'] === '0.0000'
    && $profile['recipes']['classic-stick']['ingredients'] === ['classic-log' => 1], 'four sticks use a log and preserve the raw-material valuation');
profileCheck($report['recipe_margins']['classic-rope']['margin_before_wear_cr'] === '3.5000' && $report['recipe_margins']['classic-rope']['replacement_margin_before_wear_cr'] === '-1.0000', 'self-gathered and replacement margins remain separate');
profileCheck($report['recipe_margins']['classic-nails']['batch_sale_value_cr'] === '40.0000', 'ten nails are valued per unit');
profileCheck($report['recipe_margins']['classic-chest']['margin_before_wear_cr'] === '25.0000', 'chest margin includes the existing fixed fee');
profileCheck($report['recipe_margins']['world-vegetable-broth']['margin_before_wear_cr'] === '12.0000', 'food valuation includes water and herb');
profileCheck($report['recipe_margins']['classic-anvil']['margin_before_wear_cr'] === null, 'unpriced outputs are not treated as free or offered for sale');
profileCheck(serialize($profile) === $original, 'audit leaves profile input unchanged');

$costs = $report['operating_costs'];
profileCheck($costs['recipes']['classic-chest']['outdoor']['margin_after_work_reserve_cr'] === '24.2500'
    && $costs['recipes']['classic-chest']['indoor']['margin_after_work_reserve_cr'] === '25.0000', 'chest reserve charges outdoor bench wear and keeps the fixed fee');
profileCheck($costs['recipes']['classic-chest']['outdoor']['equipment']['classic-saw']['work_wear'] === 0
    && $costs['recipes']['classic-chest']['outdoor']['equipment']['classic-saw']['minimum_durability'] === 1, 'portable craft tool must be usable without a fictitious work charge');
profileCheck($costs['recipes']['classic-nails']['outdoor']['margin_after_work_reserve_cr'] === null
    && $costs['recipes']['classic-nails']['indoor']['margin_after_work_reserve_cr'] === '16.0000', 'unpriced worn station does not become a zero cost');
profileCheck($costs['actions']['gather-log']['replacement_reserve_cr'] === '0.1500'
    && $costs['actions']['gather-log']['margin_after_work_reserve_cr'] === '1.8500', 'one completed source batch includes hammer replacement reserve');
profileCheck($costs['actions']['harvest-carrot']['margin_after_work_reserve_cr'] === '1.3000', 'free owner digging removes the old two-credit charge and keeps tool wear');
profileCheck($costs['carrot_seed_loop']['margin_after_work_reserve_cr'] === '2.8000'
    && $costs['carrot_seed_loop']['gross_sales_if_funded_cr'] === '5.0000'
    && $costs['carrot_seed_loop']['sales_less_fees_before_tax_cr'] === '3.0000'
    && $costs['carrot_seed_loop']['produced_seeds'] === 2, 'seed loop separates gross cash fees natural seed output and valued margin');
profileCheck($costs['guaranteed_daily_income'] === null, 'hypothetical sale values never guarantee daily income');

$policy = $profile['operating_costs']['treasury_example'];
$firstLoss = EconomyOperatingCosts::treasury($policy, 7200);
profileCheck(EconomyOperatingCosts::treasury($policy, 7199)['lost_cr'] === '0.0000'
    && $firstLoss['lost_cr'] === '1.0000' && $firstLoss['available_object_budget_cr'] === '89.1000', 'first treasury loss starts one full period after protection');
$dayLoss = EconomyOperatingCosts::treasury($policy, 86400);
profileCheck($dayLoss['loss_periods'] === 23 && $dayLoss['collected_to_budget_cr'] === '79.3615'
    && $dayLoss['reserved_for_parent_cr'] === '7.9361' && $dayLoss['available_object_budget_cr'] === '71.4254', 'daily treasury projection preserves compound loss and exact fractional carry');
profileCheck($dayLoss['personal_wallet_income_cr'] === '0.0000'
    && $costs['treasury_examples']['immediate']['available_object_budget_cr'] === '90.0000', 'collected funded receipts increase object budget without inventing wallet income');
$policy['gross_cr'] = '0.0001';
profileCheck(EconomyOperatingCosts::treasury($policy, 86400)['collected_to_budget_cr'] === '0.0001', 'sub-unit loss fractions are carried rather than rounded up each hour');
$policy['loss_rate_bps'] = 0;
profileCheck(EconomyOperatingCosts::treasury($policy, 604800)['lost_cr'] === '0.0000', 'zero-loss published policy is supported');
$policy['gross_cr'] = '100.0000'; $policy['loss_rate_bps'] = 10000;
$closedReceipt = EconomyOperatingCosts::treasury($policy, 86400);
profileCheck($closedReceipt['loss_periods'] === 1 && $closedReceipt['available_object_budget_cr'] === '0.0000', 'exhausted receipt closes after one full-loss period');

// The audit uses the live wear formula; the live instance wrapper retains multi-batch semantics.
$wearEngine = new EquipmentExposure(new \yii\db\Connection());
profileCheck($wearEngine->cost(['exposure_class' => 'outdoor'], 0, 3, true) === 3
    && $wearEngine->cost(['exposure_class' => 'outdoor'], 1, 3, true) === 9
    && $wearEngine->cost(['exposure_class' => 'outdoor'], 1, 3, false) === 3, 'live craft repair and tool wear keep distinct base costs across batches');
$unit = ['id' => 1, 'durability' => 100];
$exposure = ['settled_at' => 0, 'daily_wear' => $costs['daily_exposure_wear']['outdoor'], 'remainder' => 0, 'exposure_class' => 'outdoor'];
profileCheck($wearEngine->projectedWithExposure($unit, $exposure, 86400)['durability'] === 96
    && $wearEngine->projectedWithExposure($unit, $exposure, 604800)['durability'] === 72, 'live elapsed outdoor wear is four per real day and twenty-eight per week');
$exposure['daily_wear'] = 1; $exposure['exposure_class'] = 'covered';
profileCheck($wearEngine->projectedWithExposure($unit, $exposure, 86399)['durability'] === 100
    && $wearEngine->projectedWithExposure($unit, $exposure, 604800)['durability'] === 93, 'covered wear keeps partial-day remainder and one per real day');
$repairEngine = new \common\modules\world\models\domain\BuildingRepairSpec(new \yii\db\Connection());
$repair = $repairEngine->cost(['full_price' => '10.0001', 'materials' => [
    ['item_id' => 1, 'name' => 'stone', 'quantity' => 3]]], 99, 100);
profileCheck($repair['price'] === '0.1001' && $repair['materials'][0]['quantity'] === 1, 'actual premises repair rounds each material and money cost up at one percent damage');

$crop = $profile['crop'];
$rules = ['water_window_seconds' => $crop['water_window_seconds'], 'harvest_window_seconds' => $crop['harvest_window_seconds']];
$cycle = ['ready_at' => $crop['grow_seconds'], 'water_due_at' => $crop['water_interval_seconds'], 'water_missed' => 0];
$clock = \common\modules\world\models\domain\CultivationClock::project($cycle, $rules, 900);
profileCheck($clock['can_water'] && $clock['yield_factor_bps'] === 10000, 'new carrot profile exposes the actual mid-cycle watering window');
$cycle['water_due_at'] = null;
profileCheck(\common\modules\world\models\domain\CultivationClock::project($cycle, $rules, 1800)['yield_factor_bps'] === 10000, 'one timely watering after planting preserves full yield');
$cycle['water_due_at'] = 900;
profileCheck(\common\modules\world\models\domain\CultivationClock::project($cycle, $rules, 1800)['yield_factor_bps'] === 5000, 'missing the real water window reduces yield');
profileCheck($profile['actions']['harvest-carrot']['ingredients']['classic-water'] === 2, 'cycle audit includes planting water and later watering');

$broken = $profile; $broken['actions']['gather-ore']['tools'] = ['classic-pickaxe'];
$blocked = EconomyProfile::report($broken)['reachability'];
profileCheck(in_array('classic-ingot', $blocked['blocked_targets'], true) && in_array('classic-chest', $blocked['blocked_targets'], true), 'audit detects ore-ingot-iron-pickaxe bootstrap deadlock');
$broken = $profile; unset($broken['actions']['gather-magic-herb']);
profileCheck(in_array('world-explorer-elixir', EconomyProfile::report($broken)['reachability']['blocked_recipes'], true), 'starting elixir does not hide a missing renewable ingredient');
$broken = $profile; $broken['items']['classic-log']['sale_price_cr'] = 2.5;
rejectsProfile($broken, 'floating point credit input is rejected');
$broken = $profile; $broken['recipes']['classic-chest']['fee_cr'] = '-1.0000';
rejectsProfile($broken, 'negative fees are rejected');
$broken = $profile; $broken['recipes']['classic-chest']['fee_cr'] = '0.00001';
rejectsProfile($broken, 'fifth decimal requires an explicit decision');
$broken = $profile; $broken['recipes']['classic-plank']['ingredients'] = [];
rejectsProfile($broken, 'source actions cannot become zero-input crafting recipes');
$broken = $profile; $broken['recipes']['classic-plank']['ingredients'] = ['unknown-resource' => 1];
rejectsProfile($broken, 'unknown material codes are rejected');
$broken = $profile; $broken['recipes']['classic-plank']['quantity'] = 2.5;
rejectsProfile($broken, 'fractional item quantities are rejected');
$broken = $profile; $broken['recipes']['classic-plank']['work_seconds'] = 60;
rejectsProfile($broken, 'instant crafting cannot silently turn into a queue');
$broken = $profile; $broken['actions']['gather-log']['work_seconds'] = 0;
rejectsProfile($broken, 'zero-time repeatable source is rejected');
$broken = $profile; $broken['map']['max_cells'] = 72;
rejectsProfile($broken, 'map dimensions cannot disagree with profile capacity');
$broken = $profile; $broken['daily']['credits_cr'] = '25.0000';
rejectsProfile($broken, 'natural supply valuation cannot silently become minted credits');
$broken = $profile; $broken['goals']['day-1']['credit_source'] = 'mint';
rejectsProfile($broken, 'quest rewards cannot silently become an unlimited source');
$broken = $profile; $broken['items']['classic-herb']['sale_price_cr'] = null;
rejectsProfile($broken, 'incomplete daily valuation produces a controlled error');
$broken = $profile; $broken['bank_enabled'] = true;
rejectsProfile($broken, 'candidate cannot introduce a bank');
$broken = $profile; $broken['operating_costs']['craft_base_work_wear'] = 1;
rejectsProfile($broken, 'candidate cannot silently add a portable craft wear charge');
$broken = $profile; $broken['operating_costs']['treasury_example']['loss_period_seconds'] = 0;
rejectsProfile($broken, 'treasury projection rejects a zero loss interval');
$broken = $profile; $broken['operating_costs']['treasury_example']['rate_bps'] = 10001;
rejectsProfile($broken, 'treasury projection rejects rates beyond one hundred percent');
$broken = $profile; $broken['actions']['harvest-carrot']['ingredients']['classic-water'] = 1;
rejectsProfile($broken, 'cycle aggregate cannot omit mid-cycle watering');
$broken = $profile; $broken['crop']['water_fee_cr'] = '3.0000';
rejectsProfile($broken, 'cycle fee and crop fee cannot diverge');
$broken = $profile; $broken['operating_costs']['treasury_example']['funding'] = 'unlimited';
rejectsProfile($broken, 'treasury example cannot claim unfunded recurring income');

$currentProfile = $profile; $currentRoutes = EconomyScenario::load();
// Earlier accepted routes keep their original personal funding, nine open cells and fees.
$profile['starter']['funding'] = 'personal_transfer'; $profile['crop']['dig_fee_cr'] = '2.0000'; $profile['actions']['harvest-carrot']['fee_cr'] = '4.0000';
$profile['objects']['forge']['price_cr'] = '150.0000'; $profile['map']['outer_ring_multiplier'] = 1;
$profile['actions']['gather-coal']['output']['classic-coal'] = 2; $profile['actions']['gather-sand']['output']['classic-sand'] = 2; $profile['actions']['gather-magic-herb']['output']['world-magic-herb'] = 1;
$profile['map']['starter_open_cells'] = 9; $profile['map']['starter_open_square'] = [-1, 1]; $profile['map']['base_price_cr'] = '4.0000';
$routes = $currentRoutes; $routes['initial_open_cells'] = 9; $routes['municipal_budget_cr'] = '560.0000';
$routes['common_steps'] = array_values(array_filter($routes['common_steps'], static function ($step) { return $step['action'] !== 'grant'; }));
$routesBefore = serialize($routes);
$firstDay = EconomyScenario::report($profile, $routes, 'first-day');
$twoDays = EconomyScenario::report($profile, $routes, 'first-two-days');
$fourDays = EconomyScenario::report($profile, $routes, 'first-four-days');
profileCheck($firstDay['status'] === 'completed' && $firstDay['final']['claimed_goals'] === ['day-1'], 'day-one route completes its goal before further progression');
profileCheck($firstDay['final']['wallet_cr'] === '41.8000' && $firstDay['final']['budgets']['starter-site']['available_cr'] === '27.0000'
    && $firstDay['final']['budgets']['starter-site']['reserved_parent_cr'] === '9.2000', 'day one separates wallet available object budget and unspendable tax');
profileCheck($firstDay['final']['explored_cells'] === 3 && $firstDay['final']['stock']['world-explorer-elixir'] === 2, 'three real free discoveries preserve starter and reward elixirs');
$mapQuote = \common\modules\world\models\domain\WorldMapCells::purchasePricing(['base_price' => '4.0000', 'bulk_minimum' => 3, 'discount_bps' => 500],
    [['x' => 1, 'y' => 0], ['x' => 0, 'y' => 1], ['x' => -1, 'y' => 0]]);
profileCheck($mapQuote['total'] === '11.4000', 'purchasing three discovered cells would be a separate exact discounted fee');
profileCheck($twoDays['status'] === 'blocked' && $twoDays['failure']['input']['code'] === 'forge'
    && $twoDays['failure']['reason'] === 'INSUFFICIENT_FUNDS|need 150.0000, available 111.8000 at starter-site', 'two-day reference route exposes the funded cash shortfall before forge construction');
profileCheck($twoDays['failure']['requirements']['shortfall_cr'] === '38.2000'
    && $twoDays['failure']['requirements']['missing_materials'] === ['classic-plank' => 10, 'classic-stone' => 10], 'blocked build reports material deficits alongside the cash shortfall');
profileCheck($twoDays['final']['claimed_goals'] === ['day-1'] && !isset($twoDays['final']['objects']['forge'])
    && $twoDays['final']['municipal_reserved_cr'] === '343.0000', 'blocked route does not spend the future hundred-credit reward');
profileCheck($fourDays['status'] === 'completed' && $fourDays['final']['claimed_goals'] === ['day-1', 'day-2']
    && $fourDays['final']['stock']['classic-ingot'] === 2, 'unchanged profile completes the reference chain on day four with one crafted and one rewarded ingot');
profileCheck($fourDays['final']['wallet_cr'] === '41.8000' && $fourDays['final']['budgets']['starter-site']['available_cr'] === '90.1000'
    && $fourDays['final']['budgets']['garden']['available_cr'] === '0.5000', 'reward collection preserves account destinations and does not refill the wallet');
profileCheck(array_values($fourDays['final']['extraction']) === [20, 20, 20, 13], 'four-day route respects twenty extraction batches per Moscow day');
profileCheck($fourDays['final']['peak_backpack_slots'] === 16 && $fourDays['final']['stations'] === ['classic-bench' => 99, 'classic-furnace' => 99]
    && min($fourDays['final']['tools']['world-stone-pickaxe']) === 52, 'route accounts for stacks placement and actual tool station wear');
profileCheck($fourDays['final']['money_conserved'] && $fourDays['initial_total_cr'] === '810.0000'
    && $fourDays['final']['money_total_with_sinks_cr'] === '810.0000', 'all cash flows conserve personal and pre-funded municipal money');
profileCheck($fourDays['final']['remaining_orders'] === ['classic-stone' => 32, 'classic-coal' => 22, 'world-carrot' => 16]
    && $fourDays['final']['municipal_reserved_cr'] === '154.0000', 'order quantities and remaining monetary reserves exhaust together');
profileCheck(serialize($routes) === $routesBefore && EconomyScenario::report($profile, $routes, 'first-four-days') === $fourDays, 'scenario is deterministic and never changes input configuration');
profileCheck(!array_filter($fourDays['flows'], static function (array $flow): bool { return $flow['to'] === 'wallet'; }), 'route does not rely on later personal transfers');

function blockedRoute(array $profile, array $routes, string $reason, string $message): void
{
    $result = EconomyScenario::report($profile, $routes, 'first-day');
    profileCheck($result['status'] === 'blocked' && strpos($result['failure']['reason'], $reason . '|') === 0, $message);
}
$badRoutes = $routes; $badRoutes['municipal_budget_cr'] = '559.9999';
blockedRoute($profile, $badRoutes, 'INSUFFICIENT_MUNICIPAL_RESERVE', 'municipal setup cannot publish unfunded orders or rewards');
$badRoutes = $routes; $badRoutes['orders']['classic-coal']['quantity'] = 1;
blockedRoute($profile, $badRoutes, 'ORDER_QUANTITY_LIMIT', 'exhausted finite demand stops sales despite nominal item prices');
$badRoutes = $routes; $badRoutes['order_lifetime_seconds'] = 1;
blockedRoute($profile, $badRoutes, 'ORDER_EXPIRED', 'expired orders cannot finance later progress');
$badProfile = $profile; $badProfile['limits']['extraction_daily']['maximum'] = 19;
blockedRoute($badProfile, $routes, 'EXTRACTION_DAILY_LIMIT', 'one fewer extraction batch blocks the reference day-one route');
$badProfile = $profile; $badProfile['limits']['sales_daily']['gross_cr'] = '40.0000';
blockedRoute($badProfile, $routes, 'SALES_DAILY_LIMIT', 'sales share the daily gross cap across orders');
$badProfile = $profile; $badProfile['items']['classic-coal']['stack_size'] = 1;
$badProfile['storage']['backpack_slots'] = 21;
profileCheck(EconomyScenario::report($badProfile, $routes, 'first-day')['final']['peak_backpack_slots'] === 21, 'single-unit coal stacks plus sand and clay require twenty-one slots');
$badProfile['storage']['backpack_slots'] = 21;
profileCheck(EconomyScenario::report($badProfile, $routes, 'first-day')['status'] === 'completed', 'the exact active-slot boundary remains usable');
$badProfile['storage']['backpack_slots'] = 20;
blockedRoute($badProfile, $routes, 'BACKPACK_FULL', 'source results must fit actual stack capacities');
$badRoutes = $routes; $badRoutes['scenarios']['first-day'][] = ['action' => 'goal', 'code' => 'day-1'];
blockedRoute($profile, $badRoutes, 'GOAL_ALREADY_CLAIMED', 'milestone reward cannot be repeated');
$badRoutes = $routes; $badRoutes['scenarios']['first-day'][] = ['action' => 'goal', 'code' => 'day-2'];
blockedRoute($profile, $badRoutes, 'GOAL_FACT_REQUIRED', 'future goal cannot finance its own prerequisites');
$badRoutes = $routes; $badRoutes['scenarios']['first-day'][] = ['action' => 'daily'];
blockedRoute($profile, $badRoutes, 'DAILY_ALREADY_CLAIMED', 'daily supply cannot be repeated in one Moscow day');
$badRoutes = $routes; $badRoutes['scenarios']['first-day'][] = ['action' => 'craft', 'code' => 'classic-plank', 'quantity' => 31];
blockedRoute($profile, $badRoutes, 'CRAFT_HOURLY_LIMIT', 'one request for thirty-one batches cannot bypass the hourly limit');
$badRoutes = $routes; $badRoutes['common_steps'][1]['quantity'] = 5;
blockedRoute($profile, $badRoutes, 'MATERIAL_REQUIRED', 'bootstrap crafting cannot use more logs than the starting stock');
$badRoutes = $routes; $badRoutes['scenarios']['first-day'][] = ['action' => 'explore', 'code' => 'forest-plot', 'cells' => [[1, 0]]];
blockedRoute($profile, $badRoutes, 'MAP_CELL_EXPLORED', 'repeated discovery is not a new goal fact');
$badRoutes = $routes; $badRoutes['orders']['classic-brick'] = ['quantity' => 1, 'unit_price_cr' => $profile['items']['classic-brick']['sale_price_cr']];
try { EconomyScenario::report($profile, $badRoutes, 'first-day'); throw new RuntimeException('Expected raw-order rejection.'); }
catch (InvalidArgumentException $exception) { profileCheck(true, 'municipal raw orders cannot become an implicit shop for crafted products'); }
$badRoutes = $routes; $badRoutes['municipal_budget_cr'] = 560.0;
try { EconomyScenario::report($profile, $badRoutes, 'first-day'); throw new RuntimeException('Expected exact-money rejection.'); }
catch (InvalidArgumentException $exception) { profileCheck(true, 'scenario setup rejects floating point funding'); }
$badRoutes = $routes; $badRoutes['starts_at'] = 'tomorrow';
try { EconomyScenario::report($profile, $badRoutes, 'first-day'); throw new RuntimeException('Expected fixed-date rejection.'); }
catch (InvalidArgumentException $exception) { profileCheck(true, 'scenario date cannot depend on the current computer clock'); }
$badProfile = $profile; $badProfile['items']['classic-log']['stack_size'] = 0;
rejectsProfile($badProfile, 'zero-size inventory stacks are rejected');
$badProfile = $profile; $badProfile['goals']['day-1']['credit_destination'] = 'mint_wallet';
rejectsProfile($badProfile, 'milestone destination cannot bypass the funded treasury route');

$bound = EconomyBootstrapBound::report($profile, $routes['bootstrap_bound']);
$reversedTargets = $routes['bootstrap_bound']; $reversedTargets['objects'] = array_reverse($reversedTargets['objects']);
$reversedTargets['crafted_items'] = array_reverse($reversedTargets['crafted_items'], true);
$reversedBound = EconomyBootstrapBound::report($profile, $reversedTargets);
profileCheck($bound['required_raw_items'] === ['classic-clay' => 8, 'classic-coal' => 1, 'classic-fibre' => 12, 'classic-log' => 23, 'classic-ore' => 2, 'classic-sand' => 4, 'classic-stone' => 40]
    && $bound['required_raw_value_cr'] === '157.0000' && $reversedBound['required_raw_items'] === $bound['required_raw_items']
    && $reversedBound['cash_upper_bound_cr'] === $bound['cash_upper_bound_cr'], 'bootstrap expansion shares intermediate surplus and preserves the bound when target order is reversed');
profileCheck($bound['mandatory_building_prices_cr'] === '475.0000' && $bound['initial_raw_potential_cr'] === '68.5000', 'bound separates unavoidable prices and generous initial raw potential');
profileCheck($bound['best_source_batch_cr'] === '6.0000' && $bound['maximum_source_batches'] === 40
    && in_array('deep-mining', $bound['excluded_sources'], true), 'pre-ingot frontier excludes circular income from the iron pickaxe');
profileCheck($bound['seed_potential_cr'] === '1.2500' && $bound['maximum_crop_cycles'] === 44
    && $bound['maximum_crop_bonus_cr'] === '11.0000', 'seed reproduction cannot become an unlimited hidden source of optimistic cash');
profileCheck($bound['status'] === 'impossible_under_fixed_assumptions' && $bound['cash_upper_bound_cr'] === '462.5000'
    && $bound['minimum_shortfall_cr'] === '12.5000', 'even zero taxes unlimited raw demand and free time cannot fund the fixed two-calendar-day target');
profileCheck(EconomyBootstrapBound::report($profile, $routes['bootstrap_bound'], 3)['status'] === 'not_ruled_out', 'nonnegative optimistic bound never claims a feasible three-day route');
$changed = $profile; $changed['recipes']['classic-plank']['quantity'] = 1;
profileCheck(EconomyBootstrapBound::report($changed, $routes['bootstrap_bound'])['required_raw_items']['classic-log'] === 42, 'bound recalculates raw demand when a recipe output changes');
$badContract = $routes['bootstrap_bound']; $badContract['objects'][] = 'mine';
try { EconomyBootstrapBound::report($profile, $badContract); throw new RuntimeException('Expected duplicate target rejection.'); }
catch (InvalidArgumentException $exception) { profileCheck(true, 'duplicate mandatory objects cannot fabricate an impossibility proof'); }
$giftedCapital = $profile; $giftedCapital['starter']['items']['classic-pickaxe'] = 1;
try { EconomyBootstrapBound::report($giftedCapital, $routes['bootstrap_bound']); throw new RuntimeException('Expected capital rejection.'); }
catch (InvalidArgumentException $exception) { profileCheck(true, 'gifted advanced tools cannot be ignored when excluding circular resource income'); }

$unprotected = EconomyScenario::report($profile, $routes, 'first-four-days-with-nights');
$lodged = EconomyScenario::report($profile, $routes, 'return-with-lodging');
$notAssigned = EconomyScenario::report($profile, $routes, 'return-without-assignment');
$recovery = EconomyScenario::report($profile, $routes, 'late-lodging-recovery');
profileCheck($unprotected['status'] === 'blocked' && $unprotected['failure']['reason'] === 'MATERIAL_REQUIRED|world-carrot'
    && $unprotected['final']['night_health']['severity'] === 1 && $unprotected['final']['night_health']['work_efficiency_bps'] === 8000, 'four-day ideal route fails its planned crop sale once the actual night formula is applied');
profileCheck($unprotected['night_history'][0]['started_at'] === strtotime('2026-10-18T22:00:00+03:00')
    && count($unprotected['night_history']) === 1, 'one-day grace skips the first night and only completed eligible nights are settled');
profileCheck($lodged['status'] === 'completed' && $lodged['final']['night_health']['severity'] === 0
    && $lodged['night_history'][0]['protected'], 'bed placed under cover and assigned before night preserves a healthy return');
profileCheck($lodged['final']['wallet_cr'] === '41.8000' && $lodged['final']['budgets']['starter-site']['available_cr'] === '83.0000'
    && $lodged['final']['budgets']['garden']['available_cr'] === '5.0000' && $lodged['final']['money_conserved'], 'healthy return pays for meadow bed materials and cultivation from existing funds');
profileCheck($notAssigned['status'] === 'blocked' && $notAssigned['final']['night_health']['severity'] === 1
    && $notAssigned['final']['lodging_intervals'] === [], 'an installed bed without an explicit assignment does not protect a past night');
profileCheck($recovery['checkpoints']['ill-day-3']['night_health']['severity'] === 1
    && $recovery['checkpoints']['recovering-day-4']['night_health']['severity'] === 1
    && $recovery['checkpoints']['recovering-day-4']['night_health']['recovery_progress'] === 1
    && $recovery['final']['night_health']['severity'] === 0, 'late lodging does not heal retroactively and two protected nights recover one illness level');
profileCheck(count($recovery['night_history']) === 3 && $recovery['night_history'][0]['protected'] === false
    && $recovery['night_history'][1]['protected'] && $recovery['night_history'][2]['protected'], 'night checkpoint advances once per completed period during offline day jumps');
$bedRequiredRoutes = $routes;
$bedRequiredRoutes['common_steps'][] = ['action' => 'lodge', 'code' => 'starter-shelter'];
blockedRoute($profile, $bedRequiredRoutes, 'BED_REQUIRED', 'building a shelter alone cannot create free lodging');
$badNight = $routes; $badNight['night_policy']['activated_at'] = 'now';
try { EconomyScenario::report($profile, $badNight, 'return-with-lodging'); throw new RuntimeException('Expected fixed epoch rejection.'); }
catch (InvalidArgumentException $exception) { profileCheck(true, 'night analysis cannot depend on the current computer clock'); }

$fiveDays = EconomyScenario::report($profile, $routes, 'first-five-days-with-lodging');
$missed = EconomyScenario::report($profile, $routes, 'missed-day-resumed-with-lodging');
$expired = EconomyScenario::report($profile, $routes, 'missed-day-expired-crop');
$replant = EconomyScenario::report($profile, $routes, 'missed-day-unfunded-replant');
profileCheck($replant['status'] === 'completed' && $replant['final']['wallet_cr'] === '38.3000' && $replant['final']['money_conserved'], 'cheaper shelter leaves enough personal funds for replanting after a missed day');
$reducedStart = $profile; $reducedStart['starter']['credits_cr'] = '210.0000';
$unfunded = EconomyScenario::report($reducedStart, $routes, 'missed-day-unfunded-replant');
$coalExhausted = EconomyScenario::report($profile, $routes, 'coal-order-exhausted');
$stoneFallback = EconomyScenario::report($profile, $routes, 'after-coal-order-exhaustion');
profileCheck($fiveDays['status'] === 'completed' && $fiveDays['final']['at'] === '2026-10-21T10:34:00+03:00'
    && $fiveDays['final']['claimed_goals'] === ['day-1', 'day-2'] && $fiveDays['final']['stock']['classic-ingot'] === 2, 'night-safe route crafts its first ingot before claiming the second milestone on day five');
profileCheck(count($fiveDays['night_history']) === 3 && !array_filter($fiveDays['night_history'], static function ($night) { return !$night['protected']; })
    && $fiveDays['final']['night_health']['severity'] === 0, 'assigned lodging protects every eligible night through the complete early chain');
profileCheck($fiveDays['final']['placements']['starter-site'] === 10 && count($fiveDays['final']['paid_map_cells']['starter-site']) === 1
    && $fiveDays['final']['paid_map_cells']['starter-site']['starter-site:2:0']['price'] === '8.0000'
    && $fiveDays['final']['stock']['world-explorer-elixir'] === 1, 'tenth root placement requires one separately explored and paid ring-two cell');
profileCheck($fiveDays['final']['wallet_cr'] === '41.8000' && $fiveDays['final']['budgets']['starter-site']['available_cr'] === '112.0000'
    && $fiveDays['final']['budgets']['garden']['available_cr'] === '6.0000', 'five-day balances include meadow map purchase crop costs and parent reserves');
profileCheck(array_values($fiveDays['final']['extraction']) === [20, 20, 20, 20, 9] && $fiveDays['final']['peak_backpack_slots'] === 16
    && $fiveDays['final']['tools']['world-stone-pickaxe'] === [41], 'complete lodging route fits daily work inventory and remaining tool durability');
profileCheck($fiveDays['final']['remaining_orders'] === ['classic-stone' => 32, 'classic-coal' => 0, 'world-carrot' => 12]
    && $fiveDays['final']['municipal_reserved_cr'] === '78.0000', 'coal demand is finite and exhausted before the full route ends');
profileCheck($expired['status'] === 'blocked' && $expired['failure']['reason'] === 'CROP_NOT_READY_OR_EXPIRED|'
    && $expired['final']['crop']['state'] === 'expired' && !isset($expired['final']['stock']['world-carrot']), 'returning after a missed day cannot harvest the expired crop');
profileCheck($unfunded['failure']['reason'] === 'INSUFFICIENT_FUNDS|need 2.0000, available 0.3000 at garden'
    && $unfunded['final']['budgets']['starter-site']['available_cr'] === '83.0000', 'with reduced starting funds a rich site budget does not automatically pay the garden watering bill');
profileCheck($unfunded['final']['crop']['state'] === 'growing' && $unfunded['final']['stock']['classic-water'] === 5
    && $unfunded['final']['money_conserved'], 'failed watering rolls back the clock water and money for that operation');
profileCheck($missed['status'] === 'completed' && $missed['final']['at'] === '2026-10-22T10:34:00+03:00'
    && $missed['final']['stock']['classic-ingot'] === 2 && $missed['final']['night_health']['severity'] === 0, 'missed-day route resumes using existing targeted funding and reaches the ingot on day six');
profileCheck($missed['final']['claimed_daily_dates'] === ['2026-10-17', '2026-10-18', '2026-10-20', '2026-10-21', '2026-10-22']
    && !isset($missed['final']['extraction']['2026-10-19']) && count($missed['night_history']) === 4, 'offline days settle nights without accumulating supply claims or extraction quotas');
profileCheck(count($missed['final']['cancelled_crops']) === 1 && $missed['final']['cancelled_crops'][0]['state'] === 'expired'
    && $missed['final']['stock']['world-carrot-seed'] === 4 && $fiveDays['final']['stock']['world-carrot-seed'] === 6, 'expired crop cancellation refunds neither the lost seed nor the missed seed reproduction');
$grantFlows = array_values(array_filter($missed['flows'], static function ($flow) { return $flow['kind'] === 'existing_targeted_budget_grant'; }));
profileCheck(count($grantFlows) === 1 && $grantFlows[0]['from'] === 'starter-site:budget' && $grantFlows[0]['to'] === 'garden:budget'
    && $grantFlows[0]['amount_cr'] === '4.0000' && $missed['final']['wallet_cr'] === '41.8000'
    && $missed['final']['budgets']['starter-site']['available_cr'] === '108.0000', 'replant funding is an explicit four-credit budget grant without wallet refill or minting');
profileCheck($coalExhausted['status'] === 'blocked' && $coalExhausted['failure']['reason'] === 'ORDER_QUANTITY_LIMIT|'
    && $coalExhausted['final'] === $fiveDays['final'], 'exhausted coal order rejects another delivery without consuming inventory or reserved money');
profileCheck($stoneFallback['status'] === 'completed' && $stoneFallback['final']['remaining_orders'] === ['classic-stone' => 0, 'classic-coal' => 0, 'world-carrot' => 12]
    && $stoneFallback['final']['budgets']['starter-site']['available_cr'] === '155.2000'
    && $stoneFallback['final']['municipal_reserved_cr'] === '30.0000', 'remaining funded stone demand provides one bounded fallback after coal demand ends');
foreach ([$fiveDays, $missed, $stoneFallback] as $completeRoute) {
    profileCheck($completeRoute['final']['money_conserved'] && $completeRoute['final']['money_total_with_sinks_cr'] === '810.0000'
        && !array_filter($completeRoute['flows'], static function ($flow) { return $flow['to'] === 'wallet'; }), $completeRoute['scenario'] . ' conserves all original cash without further personal transfers');
}
$badRoutes = $routes;
$badRoutes['scenarios']['first-day'] = [['action' => 'explore', 'code' => 'starter-site', 'cells' => [[2, 0]]]];
blockedRoute($profile, $badRoutes, 'EXPLORER_LEVEL_REQUIRED', 'ring-two discovery cannot silently grant an explorer level');
$badRoutes['scenarios']['first-day'][0]['use_elixir'] = true;
$badRoutes['scenarios']['first-day'][0]['cells'] = [[2, 0], [2, 1], [2, 2]];
blockedRoute($profile, $badRoutes, 'MATERIAL_REQUIRED', 'batch discovery consumes one elixir per cell above the actual level');
profileCheck(EconomyScenario::report($profile, $badRoutes, 'first-day')['final'] === $firstDay['final'], 'insufficient elixirs roll back every discovery in the batch');
$badRoutes['scenarios']['first-day'][0]['cells'] = [[3, 0]];
blockedRoute($profile, $badRoutes, 'MAP_OUT_OF_BOUNDS', 'elixirs do not bypass the candidate map boundary');
$badRoutes['scenarios']['first-day'][0]['cells'] = [[2, 0], [2, 0]];
blockedRoute($profile, $badRoutes, 'MAP_DUPLICATE_CELL', 'duplicate coordinates cannot buy extra capacity twice');
$mapRoutes = $routes;
$mapRoutes['scenarios']['first-day'] = [
    ['action' => 'explore', 'code' => 'starter-site', 'cells' => [[2, 0]], 'use_elixir' => true],
    ['action' => 'buy', 'code' => 'starter-site', 'cells' => [[2, 0]]],
];
$mapBought = EconomyScenario::report($profile, $mapRoutes, 'first-day');
profileCheck($mapBought['status'] === 'completed' && $mapBought['final']['budgets']['starter-site']['available_cr'] === '19.0000'
    && $mapBought['final']['stock']['world-explorer-elixir'] === 1, 'exploration consumes the elixir and a separate purchase consumes exact budget money');
$mapRoutes['scenarios']['first-day'][] = ['action' => 'buy', 'code' => 'starter-site', 'cells' => [[2, 0]]];
blockedRoute($profile, $mapRoutes, 'MAP_CELL_NOT_DISCOVERED', 'a purchased right cannot be charged and added a second time');
$mapRoutes['scenarios']['first-day'] = [['action' => 'buy', 'code' => 'starter-site', 'cells' => [[2, 0]]]];
blockedRoute($profile, $mapRoutes, 'MAP_CELL_NOT_DISCOVERED', 'closed cells cannot be purchased before exploration');
$mapRoutes['scenarios']['first-day'] = [['action' => 'buy', 'code' => 'forest-plot', 'cells' => [[1, 0], [0, 1], [-1, 0]]]];
blockedRoute($reducedStart, $mapRoutes, 'INSUFFICIENT_FUNDS', 'nested map purchase cannot implicitly spend its parent budget with reduced starting funds');
$grantRoutes = $routes;
$grantRoutes['scenarios']['first-day'] = [['action' => 'grant', 'source' => 'starter-site', 'destination' => 'garden', 'amount_cr' => '27.0001']];
blockedRoute($profile, $grantRoutes, 'INSUFFICIENT_GRANT_BUDGET', 'targeted funding cannot spend the parent-tax reserve or silently use the wallet');
$grantRoutes['scenarios']['first-day'][0]['amount_cr'] = '0.0000';
blockedRoute($profile, $grantRoutes, 'INVALID_GRANT_AMOUNT', 'zero targeted funding is rejected');
$grantRoutes['scenarios']['first-day'][0]['amount_cr'] = '1.0000';
$grantRoutes['scenarios']['first-day'][0]['source'] = 'garden';
$grantRoutes['scenarios']['first-day'][0]['destination'] = 'starter-site';
blockedRoute($profile, $grantRoutes, 'GRANT_RELATION_REQUIRED', 'targeted funding does not invent a reverse withdrawal route');
$noMap = $routes;
$noMap['scenarios']['first-five-days-with-lodging'] = array_values(array_filter($routes['scenarios']['first-five-days-with-lodging'], static function ($step) { return $step['action'] !== 'buy'; }));
$noMapResult = EconomyScenario::report($profile, $noMap, 'first-five-days-with-lodging');
profileCheck($noMapResult['status'] === 'blocked' && $noMapResult['failure']['reason'] === 'PLACEMENT_FULL|starter-site', 'discovering the tenth root cell without buying it does not create a placement right');

$profile = $currentProfile; $routes = $currentRoutes;
$week = EconomyScenario::report($profile, $routes, 'first-seven-days-with-lodging');
$finiteWeek = EconomyScenario::report($profile, $routes, 'first-seven-days-finite-supplies');
profileCheck($finiteWeek['status'] === 'blocked' && $finiteWeek['failure']['reason'] === 'BACKPACK_FULL|21'
    && $finiteWeek['final']['money_conserved'], 'collecting every supply exposes a real backpack capacity limit before the final buildings');
$finiteRoom = $profile; $finiteRoom['storage']['backpack_slots'] = 21;
$finiteWeek = EconomyScenario::report($finiteRoom, $routes, 'first-seven-days-finite-supplies');
profileCheck($finiteWeek['status'] === 'completed' && $finiteWeek['supply_model'] === 'finite_current_runtime'
    && $finiteWeek['final']['money_conserved'] && $finiteWeek['final']['money_total_with_sinks_cr'] === '810.0000'
    && count($finiteWeek['final']['claimed_daily_dates']) === 7
    && isset($finiteWeek['final']['objects']['house'], $finiteWeek['final']['objects']['storage-building'])
    && max($finiteWeek['final']['extraction']) <= 20, 'decreasing supplies and the daily minimum complete the funded week without extra credits');
profileCheck($week['status'] === 'completed' && $week['final']['money_conserved']
    && $week['final']['money_total_with_sinks_cr'] === '810.0000', 'seven-day route conserves original funds with explicit child-budget funding');
profileCheck(($week['final']['stock']['classic-pickaxe'] ?? 0) === 1
    && isset($week['final']['objects']['workshop-building']), 'seven-day route reaches workshop and an iron pickaxe');
profileCheck(isset($week['final']['objects']['storage-building'], $week['final']['objects']['house'])
    && $profile['objects']['storage-building']['storage_slots'] === 50
    && max($week['final']['extraction']) <= 20, 'seven-day route completes medium storage and house within the unchanged extraction quota');

class EconomyAuditFixture extends \common\modules\world\commands\WorldAuditController
{
    public $captured = '';
    public function stdout($string) { $this->captured .= $string; return strlen($string); }
}
$app = new \yii\console\Application(['id' => 'economy-profile-test', 'basePath' => dirname(__DIR__, 2),
    'components' => ['db' => static function () { throw new RuntimeException('Profile audit must not access a database.'); }]]);
$controller = new EconomyAuditFixture('world-audit', $app);
profileCheck($controller->actionEconomyProfile() === 0, 'CLI succeeds without a database');
profileCheck(json_decode($controller->captured, true, 64, JSON_THROW_ON_ERROR) === $report, 'CLI emits the exact audited JSON contract');
$scenarioController = new EconomyAuditFixture('world-audit', $app);
$cliFourDays = EconomyScenario::report($profile, $routes, 'first-four-days');
profileCheck($scenarioController->actionEconomyScenario() === ($cliFourDays['status'] === 'completed' ? 0 : 1)
    && json_decode($scenarioController->captured, true, 64, JSON_THROW_ON_ERROR) === $cliFourDays, 'scenario CLI executes the current route without a database');
$scenarioController->captured = '';
$cliTwoDays = EconomyScenario::report($profile, $routes, 'first-two-days');
profileCheck($scenarioController->actionEconomyScenario('first-two-days') === ($cliTwoDays['status'] === 'completed' ? 0 : 1)
    && json_decode($scenarioController->captured, true, 64, JSON_THROW_ON_ERROR)['failure'] === $cliTwoDays['failure'], 'scenario CLI returns the current two-day audit status');
$scenarioController->captured = '';
$currentBound = EconomyBootstrapBound::report($profile, $routes['bootstrap_bound']);
profileCheck($scenarioController->actionEconomyBootstrapBound() === ($currentBound['status'] === 'impossible_under_fixed_assumptions' ? 1 : 0)
    && json_decode($scenarioController->captured, true, 64, JSON_THROW_ON_ERROR) === $currentBound, 'bound CLI reports the current profile without a database');
echo 'Economy profile checks passed.' . PHP_EOL;
