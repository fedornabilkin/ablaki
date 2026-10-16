<?php
// Pure profile/CLI audit. No database, live configuration or game writes.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\models\domain\EconomyOperatingCosts;
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
profileCheck($report['counts'] === ['items' => 57, 'objects' => 22, 'recipes' => 44, 'actions' => 14], 'candidate contains existing catalog and concrete additions');
profileCheck($report['applied'] === false && $report['effective_from'] === null, 'audit does not activate candidate');
profileCheck($report['starter_credits_cr'] === '250.0000' && $profile['starter']['credit_source'] === 'existing_transfer', 'start uses existing transfer, without minting');
profileCheck($report['daily_grant'] === ['actual_credits_cr' => '0.0000', 'reference_value_cr' => '29.0000', 'sale_value_before_limits_cr' => '14.5000'], 'natural supply value is distinct from actual credits');
profileCheck($report['first_day_objects_only_cr'] === '180.0000', 'object price subtotal excludes materials and later income');
profileCheck($report['reachability']['blocked_targets'] === [] && $report['reachability']['blocked_recipes'] === [], 'all target recipes have a physical bootstrap path');
profileCheck($report['recipe_margins']['classic-plank']['batch_sale_value_cr'] === '3.0000' && $report['recipe_margins']['classic-plank']['margin_before_wear_cr'] === '1.0000', 'plank batch revenue is two unit prices');
profileCheck($report['recipe_margins']['classic-stick']['quantity'] === 4 && $report['recipe_margins']['classic-stick']['margin_before_wear_cr'] === '0.5000', 'stick profile uses final four-unit output');
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
profileCheck($costs['actions']['harvest-carrot']['margin_after_work_reserve_cr'] === '-0.7000', 'selling all carrots loses value after water seed fees and digging wear');
profileCheck($costs['carrot_seed_loop']['margin_after_work_reserve_cr'] === '0.8000'
    && $costs['carrot_seed_loop']['gross_sales_if_funded_cr'] === '5.0000'
    && $costs['carrot_seed_loop']['sales_less_fees_before_tax_cr'] === '1.0000'
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
echo 'Economy profile checks passed.' . PHP_EOL;
