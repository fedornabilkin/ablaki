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
    checkInstall((int)(new \yii\db\Query())->from('craft_item')->count('*',$db)===45&&(int)(new \yii\db\Query())->from('craft_recipe')->count('*',$db)===35,'initial catalog is installed');
    checkInstall((int)$db->createCommand('SELECT charge_credits FROM craft_meta WHERE id=1')->queryScalar()===0,'new installation disables credit charges');
    $item=(int)(new \yii\db\Query())->select('id')->from('craft_item')->where(['code'=>'classic-log'])->scalar($db);
    $db->createCommand()->update('craft_item',['description'=>'Administrator edit'],['id'=>$item])->execute();
    $db->createCommand()->insert('craft_inventory',['user_id'=>321,'item_id'=>$item,'item_quantity'=>7,'slot'=>1])->execute();
    $db->createCommand()->update('craft_meta',['charge_credits'=>1],['id'=>1])->execute();
    $catalog=new \common\modules\craft\service\CraftCatalog(new \common\modules\craft\service\CraftStorage($db));
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
$oldContext = getenv('WORLD_TEST_SETUP'); $oldMode = getenv('WORLD_TEST_MODE');
try {
    putenv('WORLD_TEST_SETUP'); putenv('WORLD_TEST_MODE');
    foreach (['actionTestState', 'actionTestInstall', 'actionTestProfile'] as $action) {
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
    $fixturePath = sys_get_temp_dir() . '/ablaki-release-migration-' . bin2hex(random_bytes(8));
    mkdir($fixturePath, 0700);
    $fixtureFile = $fixturePath . '/m991026_000000_release_namespace_fixture.php';
    file_put_contents($fixtureFile, '<?php namespace console\\migrations; class m991026_000000_release_namespace_fixture extends \\yii\\db\\Migration { public function safeUp() { $this->createTable("release_namespace_fixture", ["id" => $this->primaryKey()]); } }');
    try {
        $migrate = new \ReflectionMethod($release, 'migrate'); $migrate->setAccessible(true);
        checkInstall($migrate->invoke($release, $fixturePath) === 0 && $migrate->invoke($release, $fixturePath) === 0,
            'namespaced historical migrations apply once through the release installer');
        checkInstall((new \yii\db\Query())->from('migration')->where(['version' => 'm991026_000000_release_namespace_fixture'])->count('*', $db) == 1,
            'migration is recorded only after its real up succeeds');
    } finally { unlink($fixtureFile); rmdir($fixturePath); }
} finally {
    putenv($oldContext === false ? 'WORLD_TEST_SETUP' : 'WORLD_TEST_SETUP=' . $oldContext);
    putenv($oldMode === false ? 'WORLD_TEST_MODE' : 'WORLD_TEST_MODE=' . $oldMode);
}
