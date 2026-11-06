<?php
// World fixture and worker credentials are strictly limited to the disposable CI database.
if (($argv[1] ?? '') === 'funding-worker') {
    $dsn=getenv('ABL_TEST_DSN');
    if(!is_string($dsn) || !preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432|33316);dbname=ablakin_ci$/D',$dsn)) throw new RuntimeException('Disposable database required.');
    define('YII_ENABLE_ERROR_HANDLER',false); $root=dirname(__DIR__,2);
    require $root.'/vendor/autoload.php'; require $root.'/vendor/yiisoft/yii2/Yii.php'; require __DIR__.'/worker-barrier.php';
    Yii::setAlias('@common',$root.'/common');
    $app=new yii\console\Application(['id'=>'funding-race','basePath'=>$root,
        'modules'=>['world'=>['class'=>common\modules\world\Module::class,'flags'=>['world_read'=>true,'world_write'=>true,'storage_v2'=>true]]],
        'components'=>['db'=>['class'=>yii\db\Connection::class,'dsn'=>$dsn,'username'=>'ablakin_ci','password'=>'ci-only-password','charset'=>'utf8'],'authManager'=>['class'=>yii\rbac\DbManager::class]]]);
    $app->params['remote_db']=$app->db; $app->setModule('user',['class'=>dektrium\user\Module::class]);
    $app->db->open(); workerReady();
    $npc=new common\modules\world\models\domain\NpcInfrastructure($app->db); $policy=$npc->policy();
    $result=common\modules\world\models\Command::run((int)$policy['bot_user_id'],bin2hex(random_bytes(16)),'fixture.funding',[],
        static fn($op)=>(new common\modules\world\models\domain\NpcFunding($app->db))->refill($policy,$npc->nodes($policy),$op,(int)$argv[2]),true);
    echo $result['amount_cr']; exit;
}
require __DIR__.'/world-market.php';

use common\modules\world\models\Command;
use common\modules\world\models\admin\{WorldControlReport,WorldEmergencyEdit};
use common\modules\world\models\domain\{NpcFunding,NpcInfrastructure,NpcMaintenance,MarketSettlement,GardenHarvest};
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\modules\craft\models\domain\{CanonicalInventory,CraftStorage,StorageAccessPolicy};
use yii\db\Query;

require_once $yiiRoot.'/console/world-migrations/m261106_210000_world_npc_funding.php';
$preserved=(new Query())->from('economy_account')->orderBy('id')->all($db);
ob_start(); try { $migration=new m261106_210000_world_npc_funding(['db'=>$db]); $migration->up(); $migration->up(); } finally { ob_end_clean(); }
$db->schema->refresh();
economyCheck($preserved===(new Query())->from('economy_account')->orderBy('id')->all($db),'funding migration is repeatable and leaves existing balances untouched');
$funding=new NpcFunding($db); $policy=$npc->policy(); $nodes=$npc->nodes($policy);
$fundState=static fn()=>(new Query())->from('world_npc_funding')->where(['site_id'=>$policy['site_node_id']])->one($db);
$emptySources=static function() use($db,$nodes): void {
    foreach($nodes as $node) if(NpcInfrastructure::role($node)==='source') {
        $account=(new EconomyHierarchy($db))->accounts((int)$node['id'])['budget'];
        $db->createCommand()->update('economy_account',['amount'=>$account['reserved']],['id'=>$account['id']])->execute();
    }
};
$db->createCommand()->update('persone',['credit'=>'10000.0000'],['user_id'=>35001])->execute();
$db->createCommand()->update('persone',['credit'=>'5000.0000'],['user_id'=>$npcUser])->execute();
// Exercise the real market settlement, excluding delivery and the city's fee from eligible bot revenue.
$fixture(static function($op) use($db,$npcUser,$market,$stone) {
    $store=new CraftStorage($db); $store->operationId=$op; $inventory=new CanonicalInventory($store);
    $target=(new StorageAccessPolicy($db))->storage($npcUser,(int)$market['storage_id'],true);
    $plan=$inventory->planCraft([],$target,[],[],$stone,5); $inventory->applyCraft($npcUser,$plan,$target,[],$stone,'fixture'); return [];
});
$state=$service->listing($city,35001); $offer=array_values(array_filter($state['buy']['items'],static fn($r)=>$r['item_id']===(int)$stone['id']))[0];
$input=array_replace($input,['inventory_id'=>$offer['inventory_id']]);
$trade(35001,$input);
$expected=Money::parse($offer['prices']['base_cr'])->add(Money::parse($offer['prices']['owner_cr']));
economyCheck(Money::parse((string)$fundState()['sales_cr'])->compare($expected)===0,'market records only actual bot proceeds, excluding city and delivery');
// Restock real NPC source output and purchase through its normal quote/execute path.
$waterItem=(new Query())->from('craft_item')->where(['code'=>'classic-water'])->one($db);
$fixture(static function($op) use($db,$npcUser,$waterStorage,$waterItem,$policy) {
    $store=new CraftStorage($db); $store->operationId=$op; $inventory=new CanonicalInventory($store);
    $target=(new StorageAccessPolicy($db))->storage($npcUser,(int)$waterStorage['id'],true);
    $plan=$inventory->planCraft([],$target,[],[],$waterItem,5); $inventory->applyCraft($npcUser,$plan,$target,[],$waterItem,'fixture');
    (new NpcMaintenance($db))->refresh($policy,$op); return [];
});
$shop=new GardenHarvest($db,$flags); $shopState=$shop->state($freshUser,(int)$waterStorage['node_id']);
$saleInput=['inventory_id'=>$shopState['items'][0]['inventory_id'],'quantity'=>1];
$quote=$shop->preview($freshUser,(int)$waterStorage['node_id'],'buy',$saleInput); $saleKey=bin2hex(random_bytes(16));
$before=$npcTotal(); $fundsBefore=$fundState();
$sale=$shop->execute($freshUser,(int)$waterStorage['node_id'],'buy',$saleInput,$saleKey,$quote['quote_id'],(array)$quote['expected_revisions']);
$afterSale=$fundState();
economyCheck($before===$npcTotal() && Money::parse((string)$afterSale['sales_cr'])->compare(Money::parse((string)$fundsBefore['sales_cr']))>0,'source sale conserves money and records revenue');
$shop->execute($freshUser,(int)$waterStorage['node_id'],'buy',$saleInput,$saleKey,$quote['quote_id'],(array)$quote['expected_revisions']);
economyCheck($afterSale===$fundState(),'sale retry cannot increase funding eligibility twice');
// Fixture sale still transfers existing cash using the same exact ledger as production.
$fixture(static function($op) use($db,$funding,$policy) {
    (new MarketSettlement($db))->pay(35001,(int)$policy['bot_user_id'],Money::parse('1000'),$op,'fixture-sale');
    $funding->record($policy,35001,(int)$policy['site_node_id'],Money::parse('1000'),$op,'warehouse');
    $funding->record($policy,35001,(int)$policy['site_node_id'],Money::parse('1000'),$op,'warehouse');
    return [];
});
$state=$fundState();
economyCheck((int)$state['share_bps']===8000 && Money::parse((string)$state['daily_limit_cr'])->decimal()==='500.0000' && (int)$state['interval_seconds']===3600,'accepted 80 percent, hourly, 500 Cr defaults');
$emptySources(); $totalBefore=$npcTotal(); $day=(new DateTimeImmutable('2026-11-06 12:00:00',new DateTimeZone('Europe/Moscow')))->getTimestamp();
$run=static fn(int $at)=>$fixture(static fn($op)=>$funding->refill($policy,$nodes,$op,$at));
$first=$run($day);
economyCheck($first['amount_cr']==='500.0000' && $npcTotal()===$totalBefore,'one refill distributes 500 existing credits without issuance');
economyCheck($run($day+3599)['reason']==='not_due','even a different operation cannot refill before one hour');
$emptySources(); $totalBefore=$npcTotal();
economyCheck($run($day+3600)['reason']==='daily_limit' && $npcTotal()===$totalBefore,'hour boundary does not bypass Moscow daily limit');
$carry=Money::parse((string)$fundState()['eligible_cr']);
$next=$run($day+86400);
economyCheck($next['amount_cr']===$carry->decimal() && Money::parse((string)$fundState()['eligible_cr'])->isZero(),'unused eligibility carries into the next day without duplicating revenue');
$emptySources(); $before=(new Query())->from('economy_account')->orderBy('id')->all($db);
$fixture(static function($op) use($db,$waterNode){ (new NpcMaintenance($db))->ensureBudget($waterNode,$op); return []; });
economyCheck($before===(new Query())->from('economy_account')->orderBy('id')->all($db),'starting a job no longer silently refills its budget');
economyCheck($run($day+90000)['reason']==='no_revenue','no new purchases means no extra source funding');
// A positive carry cannot manufacture money when the bot wallet is empty.
$fixture(static function($op) use($db,$funding,$policy){ (new MarketSettlement($db))->pay(35001,(int)$policy['bot_user_id'],Money::parse('100'),$op,'another-sale'); $funding->record($policy,35001,(int)$policy['site_node_id'],Money::parse('100'),$op,'warehouse'); return []; });
$wallet=(new Query())->select('credit')->from('persone')->where(['user_id'=>$npcUser])->scalar($db);
$db->createCommand()->update('persone',['credit'=>'0.0000'],['user_id'=>$npcUser])->execute();
economyCheck($run($day+93600)['reason']==='no_cash' && Money::parse((string)$fundState()['eligible_cr'])->decimal()==='80.0000','insufficient bot funds preserve the unused sale-backed reserve');
$db->createCommand()->update('persone',['credit'=>$wallet],['user_id'=>$npcUser])->execute();

if($db->driverName!=='sqlite') {
    $before=$npcTotal(); $children=[]; $runs=(int)(new Query())->from('world_npc_funding_run')->count('*',$db);
    foreach([1,2] as $i) {
        $command=[PHP_BINARY]; if(php_ini_loaded_file()) $command=array_merge($command,['-c',php_ini_loaded_file()]);
        $command=array_merge($command,[__FILE__,'funding-worker',(string)($day+97200)]);
        $pipes=[]; $process=proc_open(implode(' ',array_map('escapeshellarg',$command)),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)) throw new RuntimeException('Cannot start funding worker'); $children[]=[$process,$pipes];
    }
    releaseWorkers($children); $results=[];
    foreach($children as [$process,$pipes]) { $out=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($process)!==0) throw new RuntimeException($out.$err); $results[]=$out; }
    sort($results);
    economyCheck($results===['0.0000','80.0000'] && (int)(new Query())->from('world_npc_funding_run')->count('*',$db)===$runs+1 && $before===$npcTotal(),'two concurrent hourly workers transfer once, preserve cash and create one journal entry');
}

$before=$fundState();
for($i=0;$i<5;$i++) $fixture(static function($op) use($db,$funding,$policy) {
    (new MarketSettlement($db))->pay(35001,(int)$policy['bot_user_id'],Money::parse('0.0001'),$op,'fractional-sale');
    $funding->record($policy,35001,(int)$policy['site_node_id'],Money::parse('0.0001'),$op,'warehouse');
    return [];
});
economyCheck(Money::parse((string)$fundState()['eligible_cr'])->subtract(Money::parse((string)$before['eligible_cr']))->decimal()==='0.0004' && (int)$fundState()['carry']===(int)$before['carry'],'fractional sale shares carry exactly without rounding up credits');
$db->createCommand()->update('world_npc_funding',['enabled'=>0],['id'=>$fundState()['id']])->execute();
$before=$fundState(); $totalBefore=$npcTotal();
economyCheck($run($day+172800)['reason']==='not_due' && $before===$fundState() && $totalBefore===$npcTotal(),'disabled funding preserves cash, allowance and last run time');
$db->createCommand()->update('world_npc_funding',['enabled'=>1],['id'=>$fundState()['id']])->execute();
$fixture(static function($op) use($db,$funding,$policy) {
    (new MarketSettlement($db))->pay(35001,(int)$policy['bot_user_id'],Money::parse('100'),$op,'rollback-sale');
    $funding->record($policy,35001,(int)$policy['site_node_id'],Money::parse('100'),$op,'warehouse'); return [];
});
$emptySources(); $before=$fundState(); $totalBefore=$npcTotal();
$accountsBefore=(new Query())->from('economy_account')->orderBy('id')->all($db);
$runsBefore=(int)(new Query())->from('world_npc_funding_run')->count('*',$db);
try {
    $fixture(static function($op) use($funding,$policy,$nodes,$day) {
        $run=$funding->refill($policy,$nodes,$op,$day+172800);
        if(Money::parse($run['amount_cr'])->isZero()) throw new RuntimeException('Rollback fixture did not fund sources.');
        throw new RuntimeException('simulate-after-funding-failure');
    });
    throw new RuntimeException('Failed funding unexpectedly committed.');
} catch(RuntimeException $error) { if($error->getMessage()!=='simulate-after-funding-failure') throw $error; }
economyCheck($before===$fundState() && $totalBefore===$npcTotal() && $accountsBefore===(new Query())->from('economy_account')->orderBy('id')->all($db)
    && $runsBefore===(int)(new Query())->from('world_npc_funding_run')->count('*',$db),'failure after allocation rolls back balances, eligibility and journal together');

$editor=new WorldEmergencyEdit($db); $state=$fundState();
economyReject(static fn()=>$editor->prepare($freshUser,'world_npc_funding',['id'=>$state['id']],['daily_limit_cr'=>'300'],'Not an admin'),'ADMIN_REQUIRED');
economyReject(static fn()=>$editor->prepare(9001,'world_npc_funding',['id'=>$state['id']],['daily_limit_cr'=>'300'],''),'COMMENT_REQUIRED');
economyReject(static fn()=>$editor->prepare(9001,'world_npc_funding',['id'=>$state['id']],['interval_seconds'=>60],'Too frequent'),'INVALID_FUNDING_RULE');
economyReject(static fn()=>$editor->prepare(9001,'world_admin_correction',['id'=>1],['comment'=>'Replaced'],'Alter history'),'IMMUTABLE_HISTORY');
economyReject(static fn()=>$editor->prepare(9001,'world_health_event',['id'=>1],['hp_after'=>100],'Alter health history'),'IMMUTABLE_HISTORY');
$record=$editor->prepare(9001,'world_npc_funding',['id'=>$state['id']],['daily_limit_cr'=>'300'],'Уменьшаем дневной лимит на период проверки'); $operation=bin2hex(random_bytes(16));
$edited=$editor->execute(9001,$record,$operation); $editor->execute(9001,$record,$operation);
economyCheck((int)(new Query())->from('world_admin_correction')->where(['operation_id'=>$operation])->count('*',$db)===1 && Money::parse((string)$fundState()['daily_limit_cr'])->decimal()==='300.0000','audited manual correction is idempotent');
economyReject(static fn()=>$editor->execute(9001,array_replace($record,['changes'=>['daily_limit_cr'=>'200.0000']]),$operation),'CORRECTION_CONFLICT');
economyReject(static fn()=>$editor->execute(9001,$record,bin2hex(random_bytes(16))),'RECORD_CHANGED');
$account=(new EconomyHierarchy($db))->accounts((int)$waterNode->id)['budget'];
$before=$npcTotal(); $record=$editor->prepare(9001,'economy_account',['id'=>$account['id']],['amount'=>Money::parse((string)$account['amount'])->add(Money::parse('1'))->decimal()],'Исправляем тестовое расхождение бюджета');
$editor->execute(9001,$record,bin2hex(random_bytes(16)));
economyCheck(Money::parse($npcTotal())->subtract(Money::parse($before))->decimal()==='1.0000' && (new Query())->from('economy_transfer')->where(['kind'=>'admin_correction'])->exists($db),'balance correction records explicit adjustment in the money ledger');
$stack=(new Query())->from('craft_inventory')->where(['item_id'=>$waterItem['id'],'storage_id'=>$waterStorage['id']])->andWhere(['>','item_quantity',0])->one($db);
$record=$editor->prepare(9001,'craft_inventory',['id'=>$stack['id']],['item_quantity'=>0],'Удаляем испорченный тестовый остаток'); $operation=bin2hex(random_bytes(16));
$editor->execute(9001,$record,$operation); $after=$editor->read('craft_inventory',['id'=>$stack['id']]);
economyCheck((int)$after['item_quantity']===0 && $after['slot']===null && $after['item_id']===null && (new Query())->from('inventory_movement')->where(['operation_id'=>$operation,'reason'=>'admin.correction'])->exists($db),'inventory correction frees its slot and preserves movement history');
$report=(new WorldControlReport($db))->report();
economyCheck($report['counts']['objects']>0 && count($report['funding'])===1 && count($report['corrections'])>=3,'dashboard aggregates the populated world and correction history');
economyCheck(count($report['stock'])>0 && count($report['objects'])>0 && count($report['runs'])>0 && count($report['stock'])<=12,'dashboard includes bounded stock, object and funding breakdowns');
// Render real controller actions, including all categories, lists, detail and preview forms.
$app->id='app-backend'; $app->user->switchIdentity(new CraftIdentity());
foreach(array_keys(common\modules\world\admin\ControlCatalog::groups()) as $group) economyCheck(str_contains(adminPage('world/control/index',['group'=>$group]),'world-control'),'control center renders '.$group);
$html=adminPage('world/control/index',['entity'=>'world_npc_funding']);
economyCheck(str_contains($html,'500') || str_contains($html,'300'),'funding list renders actual limits');
economyCheck(str_contains(adminPage('world/control/index',['entity'=>'world_node','filter'=>['node_type'=>'PLOT']]),'world-control'),'object breakdown links accept the type filter');
economyCheck(str_contains(adminPage('world/control/view',['entity'=>'world_npc_funding','key'=>['id'=>$state['id']]]),'История исправлений'),'record detail links its audit history');
economyCheck(str_contains(adminPage('world/control/edit',['entity'=>'world_npc_funding','key'=>['id'=>$state['id']]]),'Причина исправления'),'correction form requests reason');
adminReject(405,static fn()=>adminPage('world/control/apply'),'GET cannot apply a correction');
$app->id='app-api'; adminReject(403,static fn()=>adminPage('world/control/index'),'control center unavailable through public API'); $app->id='app-backend';
echo "NPC funding and world control center checks passed.\n";
