<?php
// Exercise the real installer against an isolated database, without application credentials.
define('YII_ENABLE_ERROR_HANDLER',false);
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common',dirname(__DIR__,2).'/common');
Yii::setAlias('@console',dirname(__DIR__,2).'/console');
error_reporting(E_ALL & ~E_DEPRECATED);
$app=new \yii\console\Application(['id'=>'craft-install-tests','basePath'=>dirname(__DIR__,2),
    'components'=>['db'=>['class'=>\yii\db\Connection::class,'dsn'=>'sqlite::memory:']]]);
$db=$app->db;
$db->createCommand()->createTable('user',['id'=>'pk'])->execute();
$db->createCommand()->insert('user',['id'=>321])->execute();
$setup=new \console\controllers\CraftSetupController('craft-setup',$app);
function checkInstall($condition,$message){if(!$condition)throw new \RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function deniedInstall($setup,$action){
    try{$setup->runAction($action);}catch(\RuntimeException $e){checkInstall(strpos($e->getMessage(),'deployment context required')!==false,$action.' rejects missing environment confirmation');return;}
    throw new \RuntimeException('Unexpected installation without environment confirmation.');
}
$oldTest=getenv('CRAFT_TEST_INSTALL');$oldProduction=getenv('CRAFT_PRODUCTION_INSTALL');
try {
    putenv('CRAFT_TEST_INSTALL');putenv('CRAFT_PRODUCTION_INSTALL');
    deniedInstall($setup,'install-test');deniedInstall($setup,'install-production');
    putenv('CRAFT_TEST_INSTALL=confirmed-test-checkout');
    deniedInstall($setup,'install-production');
    checkInstall(!$db->schema->getTableSchema('craft_command',true),'rejected setup leaves the schema untouched');
    putenv('CRAFT_PRODUCTION_INSTALL=confirmed-production-checkout');
    checkInstall($setup->runAction('install-production')===0,'production installer applies craft migrations and catalog');
    $versions=(new \yii\db\Query())->select('version')->from('migration')->column($db);
    checkInstall(count($versions)===5&&in_array('m260920_190000_extend_classic_craft',$versions,true)&&in_array('m260921_100000_craft_credit_switch',$versions,true),'only the four craft migrations enter standard migration history');
    checkInstall((int)(new \yii\db\Query())->from('craft_item')->count('*',$db)===47&&(int)(new \yii\db\Query())->from('craft_recipe')->count('*',$db)===37,'initial catalog includes bed and campfire recipes');
    foreach (['classic-bed', 'classic-campfire'] as $code) {
        $output = (new \yii\db\Query())->select('id')->from('craft_item')->where(['code' => $code, 'active' => 1])->scalar($db);
        checkInstall($output && (new \yii\db\Query())->from('craft_recipe')->where(['code' => $code, 'item_id' => $output, 'active' => 1])->exists($db), $code . ' has an active recipe and matching output');
    }
    checkInstall((int)$db->createCommand('SELECT charge_credits FROM craft_meta WHERE id=1')->queryScalar()===0,'new installation disables credit charges');
    $item=(int)(new \yii\db\Query())->select('id')->from('craft_item')->where(['code'=>'classic-log'])->scalar($db);
    $db->createCommand()->update('craft_item',['description'=>'Administrator edit'],['id'=>$item])->execute();
    $db->createCommand()->insert('craft_inventory',['user_id'=>321,'item_id'=>$item,'item_quantity'=>7,'slot'=>1])->execute();
    $db->createCommand()->update('craft_meta',['charge_credits'=>1],['id'=>1])->execute();
    $catalog=new \common\modules\world\modules\craft\models\domain\CraftCatalog(new \common\modules\world\modules\craft\models\domain\CraftStorage($db));
    $before=$catalog->export();
    checkInstall($setup->runAction('install-production')===0&&$setup->runAction('install-test')===0,'both environment entry points support repeated installation');
    checkInstall($before===$catalog->export(),'repeated deployment preserves administrator catalog edits');
    checkInstall((int)$db->createCommand('SELECT item_quantity FROM craft_inventory WHERE user_id=321')->queryScalar()===7&&(int)$db->createCommand('SELECT charge_credits FROM craft_meta WHERE id=1')->queryScalar()===1,'repeated deployment preserves inventory and charging settings');
} finally {
    putenv($oldTest===false?'CRAFT_TEST_INSTALL':'CRAFT_TEST_INSTALL='.$oldTest);
    putenv($oldProduction===false?'CRAFT_PRODUCTION_INSTALL':'CRAFT_PRODUCTION_INSTALL='.$oldProduction);
}

// Release readiness must reject incomplete installations and never repair them.
class ReleaseReadinessFixture extends \console\controllers\ReleaseController {
    public $captured = '';
    public function stdout($string) { $this->captured .= $string; return strlen($string); }
}
$release = new ReleaseReadinessFixture('release', $app);
$db->createCommand()->addColumn('user', 'username', 'string')->execute();
$db->createCommand()->addColumn('user', 'blocked_at', 'integer')->execute();
$db->createCommand()->createTable('auth_assignment', ['user_id' => 'integer', 'item_name' => 'string'])->execute();
$db->createCommand()->update('user', ['username' => 'production-owner', 'blocked_at' => 0], ['id' => 321])->execute();
$db->createCommand()->insert('auth_assignment', ['user_id' => 321, 'item_name' => 'admin'])->execute();
$app->set('authManager', new class extends \yii\rbac\DbManager {
    public function checkAccess($userId, $permissionName, $params = []) { return $permissionName === 'p-admin' && in_array((int)$userId, [321, 322], true); }
});
$authorMethod = new \ReflectionMethod($release, 'publicationAuthor');
checkInstall($authorMethod->invoke($release, '') === 321, 'publication selects the existing administrator and accepts legacy unblocked zero');
$db->createCommand()->update('user', ['blocked_at' => null], ['id' => 321])->execute();
checkInstall($authorMethod->invoke($release, '') === 321, 'publication accepts modern unblocked null');
$db->createCommand()->insert('user', ['id' => 322, 'username' => 'second-admin', 'blocked_at' => 123])->execute();
$db->createCommand()->insert('auth_assignment', ['user_id' => 322, 'item_name' => 'admin'])->execute();
checkInstall($authorMethod->invoke($release, '') === 321, 'blocked administrators are excluded from publication authors');
$db->createCommand()->update('user', ['blocked_at' => null], ['id' => 322])->execute();
$db->createCommand()->insert('user', ['id' => 323, 'username' => 'regular-user', 'blocked_at' => null])->execute();
foreach (['', 'missing-owner', 'regular-user'] as $name) {
    try { $authorMethod->invoke($release, $name); throw new \LogicException('Invalid publication author accepted'); }
    catch (\RuntimeException $error) { checkInstall(str_contains($error->getMessage(), 'existing active p-admin'), 'ambiguous or missing publication author rejected'); }
}
checkInstall($authorMethod->invoke($release, 'production-owner') === 321, 'explicit existing administrator resolves ambiguity');
$db->createCommand()->update('user', ['blocked_at' => 123], ['id' => 321])->execute();
try { $authorMethod->invoke($release, 'production-owner'); throw new \LogicException('Blocked author accepted'); }
catch (\RuntimeException $error) { checkInstall(str_contains($error->getMessage(), 'existing active p-admin'), 'explicit blocked administrator rejected'); }
$oldWorldRelease = getenv('WORLD_RELEASE');
try {
    foreach ([null, 'confirmed-production-checkout'] as $context) {
        putenv($context === null ? 'WORLD_RELEASE' : 'WORLD_RELEASE=' . $context);
        try { $release->actionWorldUpdate(); throw new \LogicException('World update accepted an unpaused/foreign checkout'); }
        catch (\RuntimeException $error) { checkInstall($error->getMessage() === 'Paused production release context is required.', 'world update rejects missing production pause and foreign checkout'); }
    }
} finally {
    putenv($oldWorldRelease === false ? 'WORLD_RELEASE' : 'WORLD_RELEASE=' . $oldWorldRelease);
}
$oldContext = getenv('WORLD_TEST_SETUP'); $oldMode = getenv('WORLD_TEST_MODE');
try {
    putenv('WORLD_TEST_SETUP'); putenv('WORLD_TEST_MODE');
    foreach (['actionTestState', 'actionTestInstall', 'actionTestProfile', 'actionTestNpc', 'actionTestWorker'] as $action) {
        try { $release->$action(); throw new \LogicException('Test context was not required'); }
        catch (\RuntimeException $error) { checkInstall($error->getMessage() === 'Test release context is required.', $action . ' requires explicit test context'); }
    }
    putenv('WORLD_TEST_SETUP=confirmed-test-checkout'); putenv('WORLD_TEST_MODE=1');
    $inventoryBefore = (new \yii\db\Query())->from('craft_inventory')->all($db);
    $migrationsBefore = (new \yii\db\Query())->from('migration')->all($db);
    checkInstall($release->actionTestState() === 2 && json_decode($release->captured, true)['ready'] === false,
        'incomplete installation never reports ready');
    checkInstall($inventoryBefore === (new \yii\db\Query())->from('craft_inventory')->all($db)
        && $migrationsBefore === (new \yii\db\Query())->from('migration')->all($db), 'readiness does not alter inventory or migration history');
    $pendingMethod = new \ReflectionMethod($release, 'pending'); $pendingMethod->setAccessible(true);
    $pending = $pendingMethod->invoke($release);
    checkInstall(in_array('m260924_120000_user_activity', $pending, true)
        && !in_array('m260920_190000_extend_classic_craft', $pending, true)
        && !in_array('m260911_120000_convert_forum_text_to_utf8', $pending, true),
        'readiness verifies the additive release contract and does not replay unrelated history');

} finally {
    putenv($oldContext === false ? 'WORLD_TEST_SETUP' : 'WORLD_TEST_SETUP=' . $oldContext);
    putenv($oldMode === false ? 'WORLD_TEST_MODE' : 'WORLD_TEST_MODE=' . $oldMode);
}
