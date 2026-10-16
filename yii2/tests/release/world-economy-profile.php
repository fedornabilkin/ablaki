<?php
// Pure profile/CLI audit. No database, live configuration or game writes.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

use common\modules\world\models\domain\EconomyProfile;

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
