<?php
define('YII_ENABLE_ERROR_HANDLER',false);
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common',dirname(__DIR__,2).'/common');
$app=new \yii\console\Application(['id'=>'cleanup-tests','basePath'=>dirname(__DIR__,2),'components'=>['db'=>['class'=>\yii\db\Connection::class,'dsn'=>'sqlite::memory:']]]);
$db=$app->db;
foreach([
    'user'=>['id'=>'pk','created_at'=>'integer','last_login_at'=>'integer','confirmed_at'=>'integer','latest_activity'=>'datetime','blocked_at'=>'integer'],
    'persone'=>['id'=>'pk','user_id'=>'integer','rating'=>'decimal(18,5) DEFAULT 0','credit'=>'decimal(18,5) DEFAULT 0','balance'=>'decimal(18,5) DEFAULT 0','refovod'=>'integer DEFAULT 0'],
    'profile'=>['user_id'=>'integer','bio'=>'text'],
    'token'=>['user_id'=>'integer','created_at'=>'integer'],
    'history_balance'=>['id'=>'pk','user_id'=>'integer'],
    'game_orel'=>['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer'],
    'craft_inventory'=>['id'=>'pk','user_id'=>'integer'],
] as $table=>$fields)$db->createCommand()->createTable($table,$fields)->execute();
$created=time()-10*86400;
for($id=1;$id<=13;$id++) {
    $db->createCommand()->insert('user',['id'=>$id,'created_at'=>$created,'latest_activity'=>\common\services\user\UserActivity::date($created),'confirmed_at'=>null])->execute();
    $db->createCommand()->insert('persone',['id'=>$id,'user_id'=>$id])->execute();
}
$db->createCommand()->update('user',['confirmed_at'=>$created],['id'=>2])->execute();
$db->createCommand()->update('user',['latest_activity'=>\common\services\user\UserActivity::date(time())],['id'=>3])->execute();
$db->createCommand()->update('persone',['credit'=>10],['user_id'=>4])->execute();
$db->createCommand()->update('persone',['rating'=>5],['user_id'=>5])->execute();
$db->createCommand()->insert('history_balance',['user_id'=>6])->execute();
$db->createCommand()->insert('game_orel',['user_id'=>2,'user_gamer'=>7])->execute();
$db->createCommand()->update('persone',['refovod'=>8],['user_id'=>2])->execute();
$db->createCommand()->insert('craft_inventory',['user_id'=>9])->execute();
$db->createCommand()->insert('profile',['user_id'=>10,'bio'=>'Configured profile'])->execute();
$db->createCommand()->insert('token',['user_id'=>11,'created_at'=>time()])->execute();
$db->createCommand()->update('user',['last_login_at'=>$created+86400],['id'=>12])->execute();
$db->createCommand()->insert('profile',['user_id'=>1,'bio'=>null])->execute();
$db->createCommand()->insert('token',['user_id'=>1,'created_at'=>$created])->execute();
$service=new \common\services\user\UserClearService($db);
if(!$service->removeDead(1))throw new \RuntimeException('Dead registration must be removed.');
foreach(range(2,12) as $id)if($service->removeDead($id))throw new \RuntimeException('Protected account deleted: '.$id);
if((new \yii\db\Query())->from('profile')->where(['user_id'=>1])->exists($db)||(new \yii\db\Query())->from('token')->where(['user_id'=>1])->exists($db))throw new \RuntimeException('Empty registration relations retained.');
$db->createCommand()->addColumn('user','mail_approve','integer DEFAULT 0')->execute();$db->schema->refresh();
$db->createCommand()->update('user',['mail_approve'=>1],['id'=>13])->execute();
if($service->removeDead(13))throw new \RuntimeException('Legacy confirmation ignored.');
$db->createCommand()->update('user',['mail_approve'=>0],['id'=>13])->execute();
if(!$service->removeDead(13))throw new \RuntimeException('Dead legacy registration retained.');
$service->clear();
if((int)(new \yii\db\Query())->from('user')->count('*',$db)!==11)throw new \RuntimeException('Scheduled cleanup changed protected accounts.');
echo "PASS dead registration cleanup preserves confirmed, active and related accounts on both schema shapes\n";
