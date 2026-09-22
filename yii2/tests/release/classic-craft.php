<?php
// Standalone ephemeral SQLite or strictly allowlisted loopback CI MySQL/PostgreSQL. No live configuration.
define('YII_ENABLE_ERROR_HANDLER',false);
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/worker-barrier.php';
Yii::setAlias('@common',dirname(__DIR__,2).'/common');
Yii::setAlias('@api',dirname(__DIR__,2).'/api');
Yii::setAlias('@console',dirname(__DIR__,2).'/console');
error_reporting(E_ALL & ~E_DEPRECATED);
use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\CraftCatalog;
use common\modules\craft\service\Crafting;
use common\modules\craft\service\CraftSettings;
use yii\db\Query;
class CraftIdentity implements \yii\web\IdentityInterface {
    public static function findIdentity($id){return new self;}
    public static function findIdentityByAccessToken($token,$type=null){return $token==='craft-test'?new self:null;}
    public function getId(){return 9001;}
    public function getAuthKey(){return '';}
    public function validateAuthKey($key){return false;}
}
$dsn=getenv('ABL_TEST_DSN');
if ($dsn&&!preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432);dbname=ablakin_ci$/D',$dsn)) throw new RuntimeException('Dedicated CI DB required.');
$app=new \yii\web\Application(['id'=>'craft-tests','basePath'=>dirname(__DIR__,2),'vendorPath'=>dirname(__DIR__,2).'/vendor',
    'container'=>['definitions'=>[\yii\rest\Serializer::class=>\api\components\ListSerializer::class]],
    'modules'=>['v1'=>['class'=>\api\modules\v1\Module::class]],
    'components'=>[
        'db'=>['class'=>\yii\db\Connection::class,'dsn'=>$dsn?:'sqlite::memory:','username'=>$dsn?'ablakin_ci':null,'password'=>$dsn?'ci-only-password':null,'charset'=>'utf8'],
        'request'=>['cookieValidationKey'=>'test-only','scriptFile'=>__FILE__,'scriptUrl'=>'/index.php','hostInfo'=>'http://test.invalid'],
        'user'=>['identityClass'=>CraftIdentity::class,'enableSession'=>false],
        'urlManager'=>['enablePrettyUrl'=>true,'enableStrictParsing'=>true,'rules'=>require dirname(__DIR__,2).'/common/modules/craft/config/urlRules.php'],
    ]]);
$db=$app->db;$s=new CraftStorage($db);$catalog=new CraftCatalog($s);$engine=new Crafting($s);
if (($argv[1]??'')==='worker') {
    $db->open();
    workerReady();
    try{$engine->command(9001,$argv[2],'craft',['id'=>(int)$argv[3],'quantity'=>1]);echo 'ok';}
    catch(\yii\web\HttpException $e){echo 'rejected';}
    exit;
}
function checkCraft($condition,$message){if(!$condition)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejectsCraft(callable $call,$message){try{$call();}catch(\yii\web\HttpException $e){checkCraft(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function craftRace($id,$same){
    $children=[];
    for($i=0;$i<6;$i++){
        $key=$same?'parallel-same-key':'parallel-unique-'.$i;
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' worker '.escapeshellarg($key).' '.(int)$id;
        $pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start craft worker.');
        $children[]=[$process,$pipes];
    }
    releaseWorkers($children);
    $results=[];foreach($children as list($process,$pipes)){$out=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0||!in_array($out,['ok','rejected'],true))throw new RuntimeException($out.$err);$results[]=$out;}return $results;
}
if (!$db->schema->getTableSchema('craft_command',true)) {
    $controller=new \api\modules\v1\controllers\CraftController('craft',$app->getModule('v1'));
    try{$controller->actionIndex();throw new RuntimeException('503 expected before migration');}
    catch(\yii\web\HttpException $e){checkCraft($e->statusCode===503,'missing craft migration returns controlled 503');}
}
// Legacy schema shape, including unique output index and pre-existing inventory.
$tables=[
    'user'=>['id'=>'pk','username'=>'string'],
    'persone'=>['id'=>'pk','user_id'=>'integer','credit'=>'decimal(18,5)','balance'=>'decimal(18,5)'],
    'history_balance'=>['id'=>'pk','user_id'=>'integer','credit'=>'decimal(18,5)','balance'=>'decimal(18,5)','credit_up'=>'decimal(18,5)','balance_up'=>'decimal(18,5)','type'=>'string','comment'=>'string','created_at'=>'integer'],
    'craft_category'=>['id'=>'pk','name'=>'string NOT NULL','description'=>'text'],
    'craft_item'=>['id'=>'pk','name'=>'string NOT NULL UNIQUE','description'=>'text','category_id'=>'integer NOT NULL','active'=>'integer DEFAULT 0'],
    'craft_recipe'=>['id'=>'pk','name'=>'string NOT NULL UNIQUE','description'=>'text','category_id'=>'integer NOT NULL','item_id'=>'integer NOT NULL','active'=>'integer DEFAULT 0'],
    'craft_recipe_item'=>['id'=>'pk','recipe_id'=>'integer','item_id'=>'integer','item_quantity'=>'integer'],
    'craft_recipe_tool'=>['id'=>'pk','recipe_id'=>'integer','item_id'=>'integer'],
    'craft_inventory'=>['id'=>'pk','user_id'=>'integer','item_id'=>'integer','item_quantity'=>'integer DEFAULT 0','slot'=>'integer'],
    'craft_history'=>['id'=>'pk','user_id'=>'integer','recipe_id'=>'integer','item_id'=>'integer','created_at'=>'integer'],
];
foreach($tables as $table=>$cols)if(!$db->schema->getTableSchema($table,true))$db->createCommand()->createTable($table,$cols)->execute();
$db->createCommand()->createIndex('idx-craft_recipe-item_id','craft_recipe','item_id',true)->execute();
foreach([9001,9002] as $id){$s->insert('user',['id'=>$id,'username'=>'Craft'.$id]);$s->insert('persone',['id'=>$id,'user_id'=>$id,'credit'=>100,'balance'=>20]);}
$cat=$s->insert('craft_category',['name'=>'Legacy','description'=>'']);
$old=$s->insert('craft_item',['name'=>'Legacy item','description'=>'','category_id'=>$cat,'active'=>0]);
$s->insert('craft_inventory',['user_id'=>9002,'item_id'=>$old,'item_quantity'=>7,'slot'=>77]);
require dirname(__DIR__,2).'/console/migrations/m260920_190000_extend_classic_craft.php';
ob_start();$migration=new \m260920_190000_extend_classic_craft(['db'=>$db]);$result=$migration->up();ob_end_clean();$db->schema->refresh();
checkCraft($result!==false&&$s->quantities(9002)[$old]===7,'real additive migration preserves legacy inventory');
$settings=new CraftSettings($s);
checkCraft(!$settings->chargeCredits(),'missing settings column defaults to no debit during deployment');
require dirname(__DIR__,2).'/console/migrations/m260921_100000_craft_credit_switch.php';
ob_start();(new \m260921_100000_craft_credit_switch(['db'=>$db]))->up();ob_end_clean();$db->schema->refresh();
checkCraft(!$settings->chargeCredits()&&$s->quantities(9002)[$old]===7,'credit migration defaults off and preserves inventory');
$seed=require dirname(__DIR__,2).'/common/modules/craft/data/default-catalog.php';
$preview=$catalog->preview($seed);$catalog->apply($seed,$preview['digest']);
checkCraft(count($seed['items'])===44&&count($seed['recipes'])===34,'initial catalog: 44 items, 34 recipes, four stations');
$roundtrip=$catalog->export();$preview=$catalog->preview($roundtrip);$catalog->apply($roundtrip,$preview['digest']);
checkCraft($catalog->export()===$roundtrip,'export/import roundtrip keeps IDs, links and content');
$bad=$seed;$bad['recipes'][0]['requires']=['classic-plank'];rejectsCraft(function()use($catalog,$bad){$catalog->preview($bad);},'self dependency rejected');
$bad=$seed;$bad['recipes'][0]['ingredients'][0]['quantity']=-1;rejectsCraft(function()use($catalog,$bad){$catalog->preview($bad);},'negative import ingredient rejected');
rejectsCraft(function()use($catalog,$seed){$catalog->apply($seed,str_repeat('0',64));},'stale preview cannot overwrite catalog');
class FailingCatalogStorage extends CraftStorage { public function insert(string $table,array $values): int {if($table==='craft_recipe')throw new RuntimeException('catalog injected');return parent::insert($table,$values);} }
$brokenCatalog=new CraftCatalog(new FailingCatalogStorage($db));
$broken=$seed;$broken['items'][0]['description']='Must roll back';$broken['recipes'][0]['code']='new-recipe';$broken['recipes'][0]['name']='New recipe';
$before=$catalog->export();$check=$brokenCatalog->preview($broken);
try{$brokenCatalog->apply($broken,$check['digest']);throw new LogicException('failure expected');}catch(RuntimeException $e){if($e->getMessage()!=='catalog injected')throw $e;}
checkCraft($catalog->export()===$before,'failed import rolls back all catalog groups');
$ids=[];foreach($s->rows('craft_recipe') as $r)$ids[trim($r['code'])]=(int)$r['id'];
$items=[];foreach($s->rows('craft_item') as $r)$items[trim($r['code'])]=$r;
$seq=0;$command=function($action,$id=0,$qty=1)use($engine,&$seq){return $engine->command(9001,'craft-check-key-'.++$seq,$action,['id'=>$id,'quantity'=>$qty]);};
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-plank']);},'missing ingredients rejected with empty inventory');
$engine->command(9001,'starter-repeat-key','starter',[]);$stock=$s->quantities(9001);
$repeat=$engine->command(9001,'starter-repeat-key','starter',[]);
checkCraft($repeat['replayed']&&$s->quantities(9001)===$stock,'idempotent retry does not grant resources twice');
rejectsCraft(function()use($engine){$engine->command(9001,'starter-repeat-key','gather',[]);},'same key with different payload rejected');
rejectsCraft(function()use($command){$command('starter');},'starter only once per account');
$command('gather');rejectsCraft(function()use($command){$command('gather');},'daily gathering only once');
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-bench']);},'prerequisite recipe enforced');
$log=(int)$items['classic-log']['id'];$plank=(int)$items['classic-plank']['id'];
$db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();
foreach([1,2] as $slot)$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$log,'item_quantity'=>1,'slot'=>$slot]);
$command('craft',$ids['classic-plank'],2);
checkCraft(($s->quantities(9001)[$log]??0)===0&&$s->quantities(9001)[$plank]===4,'ingredients aggregated across slots, batch output correct');
checkCraft($s->quantities(9002)[$old]===7,'other player inventory unchanged');
// A paid fixture sharing its material with a retained tool tests additive reserve.
$patch=['version'=>1,'categories'=>[],'items'=>[],'stations'=>[],'recipes'=>[$seed['recipes'][0]]];
$patch['recipes'][0]['tools']=['classic-log'];$patch['recipes'][0]['cost_credits']=3;
$preview=$catalog->preview($patch);$catalog->apply($patch,$preview['digest']);
// A nonzero recipe price remains free with no balance; client flags cannot enable charges.
$freeTx=$db->beginTransaction();
$db->createCommand()->update('persone',['credit'=>0],['user_id'=>9001])->execute();
$s->move(9001,$items['classic-log'],3);
$free=$engine->command(9001,'free-craft-switch-test','craft',['id'=>$ids['classic-plank'],'charge_credits'=>true]);
checkCraft($free['state']['credit']===0.0&&!$free['state']['charge_credits'],'priced recipe crafts with zero balance while charges are off');
checkCraft(!(new Query())->from('history_balance')->where(['type'=>'craft'])->exists($db),'free craft does not write a balance debit');
checkCraft((int)(new Query())->from('craft_event')->where(['user_id'=>9001,'action'=>'craft'])->orderBy(['id'=>SORT_DESC])->one($db)['credit_change']===0,'free craft event records zero credits');
$freeStock=$s->quantities(9001);$settings->setChargeCredits(true,9001);
$replay=$engine->command(9001,'free-craft-switch-test','craft',['id'=>$ids['classic-plank']]);
checkCraft($replay['replayed']&&$replay['state']['credit']===0.0&&$s->quantities(9001)===$freeStock,'retry stays free after administrator enables charging');
$freeTx->rollBack();
$settings->setChargeCredits(true,9001);
$meta=(new Query())->from('craft_meta')->where(['id'=>1])->one($db);
checkCraft((int)$meta['charges_updated_by']===9001&&(int)$meta['charges_updated_at']>0,'charging switch persists actor and time');
$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$log,'item_quantity'=>1,'slot'=>3]);
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-plank']);},'consumed ingredient must leave a retained tool');
$db->createCommand()->update('craft_inventory',['item_quantity'=>2],['user_id'=>9001,'item_id'=>$log])->execute();
$command('craft',$ids['classic-plank']);
checkCraft($s->quantities(9001)[$log]===1&&(float)$engine->state(9001)['credit']===97.0,'tool retained and shared credit debited');
checkCraft((int)(new Query())->from('history_balance')->where(['user_id'=>9001,'type'=>'craft'])->count('*',$db)===1,'paid craft writes balance audit once');
// Inject failure at the last write, after inventory/balance/history; entire operation must roll back.
class FailingCraftStorage extends CraftStorage { public function insert(string $table,array $values): int {if($table==='craft_command')throw new RuntimeException('injected');return parent::insert($table,$values);} }
$db->createCommand()->update('craft_inventory',['item_quantity'=>3],['user_id'=>9001,'item_id'=>$log])->execute();
$before=$engine->state(9001);
try{(new Crafting(new FailingCraftStorage($db)))->command(9001,'injected-failure-key','craft',['id'=>$ids['classic-plank']]);throw new LogicException('failure expected');}catch(RuntimeException $e){if($e->getMessage()!=='injected')throw $e;}
checkCraft($engine->state(9001)===$before,'late write failure rolls inventory, balance and progression back');
$db->createCommand()->update('persone',['credit'=>0],['user_id'=>9001])->execute();
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-plank']);},'insufficient shared credit rejected');
// Full inventory rollback, invalid quantities and disabled entries.
$db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();
for($i=1;$i<=100;$i++)$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$log,'item_quantity'=>100,'slot'=>$i]);
$db->createCommand()->update('persone',['credit'=>100],['user_id'=>9001])->execute();
$before=$engine->state(9001);rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-plank']);},'full inventory rejects craft');
checkCraft($engine->state(9001)===$before,'full inventory failure restores ingredients');
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-plank'],-1);},'negative command quantity rejected');
$db->createCommand()->update('craft_item',['active'=>0],['id'=>$log])->execute();
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-plank']);},'disabled ingredient rejected');
checkCraft(count($engine->state(9002)['inventory'])===1,'disabled legacy inventory remains visible');
$db->createCommand()->update('craft_item',['active'=>1],['id'=>$log])->execute();
// HTTP filters actually run; caller cannot choose another account or use GET mutations.
function craftDispatch($method,$path,$auth,$body=[]){$app=Yii::$app;$_SERVER['REQUEST_METHOD']=$method;$app->user->setIdentity(null);$app->request->headers->removeAll();if($auth)$app->request->headers->set('Authorization','Bearer craft-test');$app->request->setPathInfo($path);$app->request->setQueryParams(['envelope'=>'1']);$app->request->setBodyParams($body);$route=$app->urlManager->parseRequest($app->request);if(!$route)return [404,null];try{return [200,$app->runAction($route[0],$route[1])];}catch(\yii\web\HttpException $e){return [$e->statusCode,null];}}
checkCraft(craftDispatch('GET','v1/craft',false)[0]===401,'API requires authentication');
checkCraft(craftDispatch('GET','v1/craft/command',true)[0]!==200,'GET cannot mutate craft state');
list($status,$state)=craftDispatch('GET','v1/craft',true);checkCraft($status===200&&$state['slots_used']===100&&$state['slot_limit']===100&&count($state['inventory_slots'])===100,'API returns 100 real occupied slots for authenticated owner');
list($status,$history)=craftDispatch('GET','v1/craft/history',true);checkCraft($status===200&&isset($history['items'],$history['_meta']),'API history envelope dispatch');
foreach($history['items'] as $entry)checkCraft((int)$entry['user_id']===9001,'history owner enforced');
list($status,$result)=craftDispatch('POST','v1/craft/command',true,['action'=>'discard','id'=>$log,'quantity'=>1,'user_id'=>9002,'request_key'=>'http-command-test']);
checkCraft($status===200&&isset($result['state'])&&$s->quantities(9002)[$old]===7,'POST command ignores supplied owner and returns real state');
// Slot deletion cannot spill into another stack, target another owner, or change on retry.
$slotTx=$db->beginTransaction();
$db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();
$firstSlot=$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$log,'item_quantity'=>7,'slot'=>1]);
$secondSlot=$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$log,'item_quantity'=>5,'slot'=>2]);
$foreignSlot=(int)(new Query())->from('craft_inventory')->where(['user_id'=>9002])->one($db)['id'];
$discard=['id'=>$log,'quantity'=>2,'slot_id'=>$secondSlot];
$deleted=$engine->command(9001,'discard-specific-slot','discard',$discard);
checkCraft($deleted['state']['inventory_slots'][0]['quantity']===7&&$deleted['state']['inventory_slots'][1]['quantity']===3,'discard removes only the dragged stack quantity');
$repeated=$engine->command(9001,'discard-specific-slot','discard',$discard);
checkCraft($repeated['replayed']&&$repeated['state']['inventory_slots']===$deleted['state']['inventory_slots'],'slot discard replay does not remove more items');
$changed=$discard;$changed['slot_id']=$firstSlot;
rejectsCraft(function()use($engine,$changed){$engine->command(9001,'discard-specific-slot','discard',$changed);},'idempotency includes target slot');
rejectsCraft(function()use($engine,$log,$foreignSlot){$engine->command(9001,'discard-foreign-slot','discard',['id'=>$log,'quantity'=>1,'slot_id'=>$foreignSlot]);},'another owner slot cannot be discarded');
rejectsCraft(function()use($engine,$log,$secondSlot){$engine->command(9001,'discard-excess-slot','discard',['id'=>$log,'quantity'=>4,'slot_id'=>$secondSlot]);},'slot shortage cannot consume another stack');
rejectsCraft(function()use($engine,$plank,$secondSlot){$engine->command(9001,'discard-replaced-slot','discard',['id'=>$plank,'quantity'=>1,'slot_id'=>$secondSlot]);},'changed item in slot cannot be discarded');
$db->createCommand()->update('craft_inventory',['item_quantity'=>250],['id'=>$secondSlot])->execute();
$engine->command(9001,'discard-large-stack','discard',['id'=>$log,'quantity'=>250,'slot_id'=>$secondSlot]);
checkCraft($s->quantities(9001)[$log]===7,'whole stacks above 100 units can be discarded');
$slotTx->rollBack();
// Old empty rows and overflow remain compatible with the new 100 occupied-cell limit.
$slotTx=$db->beginTransaction();$db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();
for($i=1;$i<=150;$i++)$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>null,'item_quantity'=>0,'slot'=>$i]);
$s->move(9001,$items['classic-log'],10000);
checkCraft($engine->state(9001)['slots_used']===100,'legacy empty rows do not reduce 100-slot capacity');
rejectsCraft(function()use($s,$items){$s->move(9001,$items['classic-plank'],1);},'empty legacy rows cannot bypass 100-slot limit');
$s->move(9001,$items['classic-log'],-1);$s->move(9001,$items['classic-log'],1);
checkCraft($s->quantities(9001)[$log]===10000,'existing stack can be topped up at capacity');
$s->move(9001,$items['classic-log'],-100);$s->move(9001,$items['classic-plank'],1);
checkCraft($engine->state(9001)['slots_used']===100,'freed slot can be reused at new limit');
$s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$old,'item_quantity'=>7,'slot'=>151]);
checkCraft($engine->state(9001)['slots_used']===101&&$s->quantities(9001)[$old]===7,'pre-existing overflow stays visible without losing items');
$slotTx->rollBack();
// Station, consumable, minimum level and an entire content tree can be exercised in a rolled-back fixture.
$tx=$db->beginTransaction();
$db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();
$db->createCommand()->delete('craft_command',['user_id'=>9001])->execute();
$preview=$catalog->preview($seed);$catalog->apply($seed,$preview['digest']);
foreach($seed['recipes'] as $row){
    foreach($row['ingredients'] as $ing)$s->move(9001,$items[$ing['item']],$ing['quantity']*2);
    foreach($row['tools'] as $tool)$s->move(9001,$items[$tool],1);
    if($row['min_level']>1){$categoryId=(int)$items[$row['output']]['category_id'];$db->createCommand()->delete('craft_skill',['user_id'=>9001,'category_id'=>$categoryId])->execute();$db->createCommand()->insert('craft_skill',['user_id'=>9001,'category_id'=>$categoryId,'experience'=>100])->execute();}
    $command('craft',$ids[$row['code']]);
    $db->createCommand()->delete('craft_command',['user_id'=>9001])->execute();
}
checkCraft((int)(new Query())->from('craft_known')->where(['user_id'=>9001])->count('*',$db)===34,'all 34 recipes form a traversable dependency tree');
$potion=(int)$items['classic-potion']['id'];$before=$s->quantities(9001)[$potion];$command('use',$potion);
checkCraft(($s->quantities(9001)[$potion]??0)===$before-1,'consumable is removed on use');
$furnace=(int)$items['classic-furnace']['id'];$db->createCommand()->delete('craft_inventory',['user_id'=>9001,'item_id'=>$furnace])->execute();
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-ingot']);},'missing station rejected');
$db->createCommand()->delete('craft_skill',['user_id'=>9001])->execute();
rejectsCraft(function()use($command,$ids){$command('craft',$ids['classic-sword']);},'minimum category level enforced');
$tx->rollBack();
class CraftAdminUser extends \yii\web\User { public $admin=false; public function can($permissionName,$params=[],$allowCaching=true){return $this->admin&&$permissionName==='p-admin';} }
$app->set('user',['class'=>CraftAdminUser::class,'identityClass'=>CraftIdentity::class,'enableSession'=>false]);
$module=new \common\modules\craft\Module('craft',$app);
$admin=new \common\modules\craft\controllers\CatalogController('catalog',$module);
$_SERVER['REQUEST_METHOD']='GET';$app->id='app-backend';
rejectsCraft(function()use($admin){$admin->runAction('export');},'guest cannot export administrative catalog');
rejectsCraft(function()use($admin){$admin->runAction('settings');},'guest cannot change charging');
$app->user->setIdentity(new CraftIdentity);
rejectsCraft(function()use($admin){$admin->runAction('export');},'regular user cannot export administrative catalog');
rejectsCraft(function()use($admin){$admin->runAction('settings');},'regular user cannot change charging');
$app->user->admin=true;
checkCraft($admin->runAction('export') instanceof \yii\web\Response,'p-admin can export catalog');
$_SERVER['REQUEST_METHOD']='POST';$app->request->setBodyParams([]);
rejectsCraft(function()use($admin){$admin->runAction('import');},'admin import requires CSRF token');
rejectsCraft(function()use($admin){$admin->runAction('settings');},'charging switch requires CSRF token');
$_SERVER['REQUEST_METHOD']='GET';
rejectsCraft(function()use($admin){$admin->runAction('import');},'admin import cannot run with GET');
rejectsCraft(function()use($admin){$admin->runAction('settings');},'charging switch cannot run with GET');
class CraftTestSession extends \yii\web\Session { public function setFlash($key,$value=true,$removeAfterAccess=true){} }
$app->set('session',['class'=>CraftTestSession::class]);
$_SERVER['REQUEST_METHOD']='POST';
$token=$app->request->getCsrfToken();
$app->request->setBodyParams([$app->request->csrfParam=>$token,'charge_credits'=>'0']);
checkCraft($admin->runAction('settings') instanceof \yii\web\Response&&!$settings->chargeCredits(),'admin POST with CSRF can disable charging');
$app->request->setBodyParams([$app->request->csrfParam=>$token,'charge_credits'=>['1']]);
rejectsCraft(function()use($admin){$admin->runAction('settings');},'settings reject malformed switch values');
$app->request->setBodyParams([$app->request->csrfParam=>$token,'charge_credits'=>'1']);
checkCraft($admin->runAction('settings') instanceof \yii\web\Response&&$settings->chargeCredits(),'admin POST with CSRF can explicitly enable charging');
$app->id='app-api';
rejectsCraft(function()use($admin){$admin->runAction('export');},'admin actions unavailable through public applications');
if($dsn){
    $db->createCommand()->delete('craft_inventory',['user_id'=>9001])->execute();$db->createCommand()->delete('craft_command',['user_id'=>9001])->execute();
    $s->insert('craft_inventory',['user_id'=>9001,'item_id'=>$log,'item_quantity'=>2,'slot'=>1]);$db->createCommand()->update('persone',['credit'=>3],['user_id'=>9001])->execute();
    $results=craftRace($ids['classic-plank'],false);
    checkCraft(count(array_filter($results,static function($v){return $v==='ok';}))===1&&$s->quantities(9001)[$log]===1&&(float)$engine->state(9001)['credit']===0.0,'parallel distinct requests cannot double-spend last resources and credits');
    $db->createCommand()->update('craft_inventory',['item_quantity'=>2],['user_id'=>9001,'item_id'=>$log])->execute();$db->createCommand()->update('persone',['credit'=>3],['user_id'=>9001])->execute();
    $results=craftRace($ids['classic-plank'],true);
    checkCraft(count(array_filter($results,static function($v){return $v==='ok';}))===6&&$s->quantities(9001)[$log]===1&&(float)$engine->state(9001)['credit']===0.0,'parallel same-key requests all succeed with one debit');
}
// The allowlisted disposable database also covers installations without any legacy craft tables.
foreach(['craft_event','craft_command','craft_known','craft_skill','craft_dependency','craft_history','craft_recipe_tool','craft_recipe_item','craft_inventory','craft_recipe','craft_station','craft_item','craft_category','craft_meta'] as $table)$db->createCommand()->dropTable($table)->execute();
$db->schema->refresh();ob_start();$migration=new \m260920_190000_extend_classic_craft(['db'=>$db]);$result=$migration->up();ob_end_clean();$db->schema->refresh();
checkCraft($result!==false,'migration creates only missing baseline craft tables on a fresh installation');
ob_start();(new \m260921_100000_craft_credit_switch(['db'=>$db]))->up();ob_end_clean();$db->schema->refresh();
checkCraft(!$settings->chargeCredits(),'fresh installation starts with charges disabled');
$preview=$catalog->preview($seed);$catalog->apply($seed,$preview['digest']);
$result=$engine->command(9002,'fresh-install-starter','starter',[]);
checkCraft(count($result['state']['items'])===44&&count($result['state']['recipes'])===34&&count($result['state']['inventory'])===10,'fresh schema imports full catalog and accepts first command');
$app->params['remote_db']=$db;
foreach(glob(dirname(__DIR__,2).'/console/migrations/m230317_*_create_craft_*_table.php') as $file){require_once $file;$class=basename($file,'.php');ob_start();$legacyResult=(new $class(['db'=>$db]))->up();ob_end_clean();checkCraft($legacyResult!==false,'older migration recognizes installed craft schema: '.$class);}
checkCraft(count($catalog->export()['items'])===44,'later normal migrations preserve initialized craft data');
echo 'Classic craft checks passed: '.$db->driverName.PHP_EOL;
