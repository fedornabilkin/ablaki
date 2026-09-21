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
    checkInstall(count($versions)===3&&in_array('m260920_190000_extend_classic_craft',$versions,true)&&in_array('m260921_100000_craft_credit_switch',$versions,true),'only the two craft migrations enter standard migration history');
    checkInstall((int)(new \yii\db\Query())->from('craft_item')->count('*',$db)===44&&(int)(new \yii\db\Query())->from('craft_recipe')->count('*',$db)===34,'initial catalog is installed');
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
