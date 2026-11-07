<?php
/** Pure audit: no database connection or player operations. */
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

use common\modules\world\domain\ConstructionPacing;
use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\modules\economy\value\Money;

function pacingCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}

$profile = EconomyProfile::load();
EconomyProfile::validate($profile);
$original = serialize($profile);
$report = ConstructionPacing::report($profile);
pacingCheck($report['route']['buildings'] === 30 && $report['route']['templates'] === 29, 'Route includes the second greenhouse bed and each nested storage objective.');
pacingCheck($report['route']['price_cr'] === '3970.0000' && $report['route']['work_seconds'] === 76770, 'Full route requires 3970 credits and 21h 19m 30s of construction.');
pacingCheck($report['route']['materials']['classic-plank'] === 340 && $report['route']['materials']['classic-rope'] === 69, 'Required materials include repeated object types.');
pacingCheck($report['starter_core']['price_cr'] === '220.0000' && $report['starter_core']['remaining_before_materials_cr'] === '30.0000', 'Five core buildings fit the city start grant before materials and fees.');
foreach (['forest-plot', 'stone-plot', 'clay-plot', 'sand-plot', 'meadow-plot', 'water-source', 'trap'] as $code) {
    pacingCheck($profile['objects'][$code]['work_seconds'] >= 600, 'Source preparation takes at least ten minutes: ' . $code);
}
foreach (['mine', 'forge', 'workshop-building', 'storage-building', 'warehouse', 'small-greenhouse', 'field-kitchen', 'workroom', 'garden', 'cookfire'] as $code) {
    pacingCheck($profile['objects'][$code]['work_seconds'] >= 1800, 'Production infrastructure takes at least thirty minutes: ' . $code);
}
pacingCheck($profile['objects']['workshop-building']['work_seconds'] >= 5400 && $profile['objects']['mine']['work_seconds'] >= 5400, 'Workshop and mine are substantial ninety-minute projects.');
pacingCheck($profile['objects']['estate']['work_seconds'] === $profile['objects']['house']['work_seconds'] * 6, 'Estate requires six times the house construction effort.');
pacingCheck($report['objects']['drilling-rig']['team_elapsed_seconds'][4] === 5400, 'Four simultaneous full-efficiency workers need ninety minutes for the rig.');
pacingCheck($report['solo_construction_sessions'][1]['construction_only_days'] === 15 && $report['calendar_completion_days'] === null, 'Session count does not pretend to be a guaranteed calendar completion time.');
$sum = Money::parse('0'); $seconds = 0;
foreach ($report['stages'] as $stage) { $sum = $sum->add(Money::parse($stage['price_cr'])); $seconds += $stage['work_seconds']; }
pacingCheck($sum->decimal() === $report['route']['price_cr'] && $seconds === $report['route']['work_seconds'], 'Stage subtotals reconcile with the complete route.');
$changed = $profile;
$changed['objects']['garden-bed']['price_cr'] = '10.1234';
$changed['objects']['garden-bed']['work_seconds'] = 601;
$changedReport = ConstructionPacing::report($changed);
pacingCheck($changedReport['route']['price_cr'] === '3970.2468' && $changedReport['route']['work_seconds'] === 76772, 'Audit uses exact money and includes both beds after a terms change.');
unset($changed['objects']['mine']);
try { ConstructionPacing::report($changed); throw new RuntimeException('Missing terms silently ignored.'); }
catch (InvalidArgumentException $e) { pacingCheck(true, 'Missing route terms fail instead of understating the cost.'); }
pacingCheck(serialize($profile) === $original && !$report['applied'], 'Audit never changes the candidate.');
