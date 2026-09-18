<?php
// Dedicated ephemeral CI databases only. Never loads application .env or live DB config.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@console', dirname(__DIR__, 2) . '/console');
error_reporting(E_ALL & ~E_DEPRECATED);

use common\models\user\Person;
use common\models\user\User;
use common\modules\games\models\GameFive;
use common\modules\games\models\GameFiveHod;
use common\modules\games\service\FiveService;

$dsn = getenv('ABL_TEST_DSN');
if (!is_string($dsn) || !preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432);dbname=ablakin_ci$/D', $dsn)) {
    throw new RuntimeException('Requires the dedicated loopback ablakin_ci database.');
}
$app = new \yii\console\Application([
    'id'=>'database-release-test', 'basePath'=>dirname(__DIR__,2),
    'modules'=>['user'=>['class'=>\dektrium\user\Module::class]],
    'components'=>[
        'db'=>['class'=>\yii\db\Connection::class, 'dsn'=>$dsn, 'username'=>'ablakin_ci', 'password'=>'ci-only-password', 'charset'=>'utf8'],
        'user'=>['class'=>\yii\web\User::class, 'identityClass'=>User::class, 'enableSession'=>false],
        'i18n'=>['translations'=>['*'=>['class'=>\yii\i18n\PhpMessageSource::class,'basePath'=>'@common/messages']]],
    ],
]);
$db=$app->db;
$db->open();
if (($argv[1] ?? '') === 'worker') {
    list(,,$action,$userId,$gameId,$roundId,$start)=$argv;
    $person=Person::findOne(['user_id'=>(int)$userId]);
    $game=$gameId ? GameFive::findOne((int)$gameId) : null;
    $app->user->setIdentity(User::findOne((int)$userId));
    while (microtime(true)<(float)$start) usleep(1000);
    try {
        $service=new FiveService();
        if ($action==='create') $service->create(new GameFive(['kon'=>10,'ball'=>4]), $person);
        elseif ($action==='cancel') $service->cancel($game,$person);
        elseif ($action==='move') $service->move($game,$person,5,(int)$roundId);
        elseif ($action==='exchange') {
            $model=new \common\modules\exchange\api\models\CreditExchange(['type'=>'buy','credit'=>10,'amount'=>1,'count'=>1]);
            (new \common\modules\exchange\service\ExchangeService())->create($model);
        }
        elseif ($action === 'transfer-claim' || $action === 'transfer-cancel') {
            $transfer = new \common\modules\exchange\models\CreditTransfer(['id' => (int)$gameId]);
            $transfers = new \common\modules\exchange\service\TransferService();
            if ($action === 'transfer-claim') $transfers->confirm($transfer, 'fixture-code');
            else $transfers->delete($transfer);
        }
        elseif ($action === 'bot') (new \common\services\game\GameCreateService())->execute();
        elseif (strpos($action, 'delete-') === 0 || strpos($action, 'bulk-') === 0) {
            $kind = substr($action, strpos($action, '-') + 1);
            (new \common\modules\games\service\GameCancellationService())->cancel($kind, (int)$userId,
                strpos($action, 'bulk-') === 0 ? null : (int)$gameId);
        }
        elseif (strpos($action, 'join-') === 0) {
            $table = 'game_' . substr($action, 5);
            \common\modules\games\service\GameParticipation::run($table, (int)$gameId, $person, function (array $row) use ($db, $table, $userId) {
                if ((int)$row['user_gamer'] !== 0) throw new \yii\web\ConflictHttpException('Already started.');
                $ledger = new \common\services\user\CreditLedger($db);
                $method = $table === 'game_saper' ? 'changeBalance' : 'change';
                $ledger->$method((int)$userId, -(float)$row['kon'], $table, 'Join fixture');
                $db->createCommand()->update($table, ['user_gamer' => (int)$userId], ['id' => $row['id']])->execute();
            });
        }
        else throw new RuntimeException('Unknown worker action.');
        echo 'ok';
    } catch (\yii\web\HttpException $expected) { echo 'rejected'; }
    catch (\common\modules\exchange\exception\CountException $expected) { echo 'rejected'; }
    exit;
}
function verifyDb(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
function race(array $commands): array {
    $children=[]; $start=microtime(true)+2;
    foreach ($commands as $args) {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' worker';
        foreach (array_merge($args,[$start]) as $arg) $command.=' '.escapeshellarg((string)$arg);
        $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start DB worker.');
        fclose($pipes[0]); $children[]=[$process,$pipes];
    }
    $results=[];
    foreach ($children as list($process,$pipes)) {
        $out=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        if ($code!==0 || !in_array($out,['ok','rejected'],true)) throw new RuntimeException('DB worker failed: '.$out.' '.$err);
        $results[]=$out;
    }
    return $results;
}
$tables=[
    'user'=>['id'=>'pk','username'=>'string','email'=>'string','created_at'=>'integer','last_login_at'=>'integer'],
    'persone'=>['id'=>'pk','user_id'=>'integer','credit'=>'decimal(18,5)','balance'=>'decimal(18,5)','rating'=>'decimal(18,5)','bonus_count'=>'integer NOT NULL DEFAULT 0'],
    'game_five'=>['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer','kon'=>'decimal(18,5)','status'=>'string','user_amount'=>'integer','gamer_amount'=>'integer','created_at'=>'integer','updated_at'=>'integer'],
    'game_five_hod'=>['id'=>'pk','game_five_id'=>'integer','user_id'=>'integer','user_gamer'=>'integer','user_ball'=>'integer','gamer_ball'=>'integer','status'=>'string','user_amount'=>'integer','gamer_amount'=>'integer','created_at'=>'integer'],
    'history_balance'=>['id'=>'pk','user_id'=>'integer','balance'=>'decimal(18,5)','credit'=>'decimal(18,5)','balance_up'=>'decimal(18,5)','credit_up'=>'decimal(18,5)','type'=>'string','comment'=>'string','created_at'=>'integer'],
    'history_rating'=>['id'=>'pk','user_id'=>'integer','rating'=>'decimal(18,5)','rating_up'=>'decimal(18,5)','type'=>'string','comment'=>'string','created_at'=>'integer'],
    'comission'=>['id'=>'pk','type'=>'string','amount'=>'decimal(18,5)','created_at'=>'integer'],
    'credit_exchange'=>['id'=>'pk','user_id'=>'integer','user_buyer'=>'integer NOT NULL DEFAULT 0','type'=>'string','credit'=>'decimal(18,5)','amount'=>'decimal(18,5)','created_at'=>'integer','updated_at'=>'integer'],
];
foreach ($tables as $table=>$columns) $db->createCommand()->createTable($table,$columns)->execute();
foreach ([1,2,3] as $id) {
    $db->createCommand()->insert('user',['id'=>$id,'username'=>'Player'.$id])->execute();
    $db->createCommand()->insert('persone',['id'=>$id,'user_id'=>$id,'credit'=>100,'balance'=>100,'rating'=>10])->execute();
}
$credit=static function($id)use($db){return (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=:id',[':id'=>$id])->queryScalar();};
$count=static function($table)use($db){return (int)(new \yii\db\Query())->from($table)->count('*',$db);};
$reset=static function()use($db){
    foreach (['game_five_hod','game_five','history_balance','history_rating','comission','credit_exchange'] as $table) $db->createCommand()->delete($table)->execute();
    $db->createCommand()->update('persone',['credit'=>100,'balance'=>100,'rating'=>10])->execute();
};
$db->createCommand()->update('persone',['credit'=>10],['user_id'=>1])->execute();
$results=race(array_fill(0,8,['create',1,0,0]));
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $credit(1)===0.0 && $count('game_five')===1 && $count('history_balance')===1,'parallel creates cannot overspend the last stake');
$reset();
$service=new FiveService();
$game=$service->create(new GameFive(['kon'=>10,'ball'=>4]),Person::findOne(1));
$id=$game->id; $round=$game->getLastHod()->id;
$results=race(array_fill(0,8,['move',2,$id,$round]));
$game=GameFive::findOne($id);
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $credit(2)===90.0 && (int)$game->user_amount===9 && $count('history_balance')===2,'parallel join/reply reserves and scores exactly once');
$service->move($game,Person::findOne(1),4,(int)$round);
$round=$game->getLastHod()->id;
$service->move($game,Person::findOne(2),5,(int)$round);
$service->move($game,Person::findOne(1),4,(int)$round);
$round=$game->getLastHod()->id;
$results=race(array_fill(0,8,['move',2,$id,$round]));
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $credit(1)===109.0 && $credit(2)===90.0
    && $count('history_balance')===3 && $count('history_rating')===1 && $count('comission')===1,'parallel final replies pay and record commission once');
$reset();
$game=$service->create(new GameFive(['kon'=>10,'ball'=>4]),Person::findOne(1));
$results=race(array_fill(0,8,['cancel',1,$game->id,0]));
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $credit(1)===100.0 && $count('game_five')===0 && $count('history_balance')===2,'parallel cancellation refunds once');
$reset();
$game=$service->create(new GameFive(['kon'=>10,'ball'=>4]),Person::findOne(1));
$results=race([['cancel',1,$game->id,0],['move',2,$game->id,$game->getLastHod()->id]]);
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1
    && (($count('game_five')===0 && $credit(1)===100.0 && $credit(2)===100.0) || ($count('game_five')===1 && $credit(1)===90.0 && $credit(2)===90.0)),'join versus cancellation has one consistent outcome');
$reset();
$results=race(array_fill(0,8,['exchange',1,0,0]));
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $count('credit_exchange')===1
    && $credit(1)===90.0 && $count('history_balance')===1,'parallel exchange creates respect rating limit and balance');

// New transfer claims and bot replenishment use real database locks, too.
$db->createCommand()->createTable('credit_transfer', ['id'=>'pk','user_id'=>'integer','user_buyer'=>'integer NOT NULL DEFAULT 0','amount'=>'integer','password'=>'string','created_at'=>'integer','updated_at'=>'integer'])->execute();
$db->createCommand()->createTable('game_duel', ['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer','kon'=>'decimal(18,5)','u1'=>'integer','b1'=>'integer','u2'=>'integer','b2'=>'integer','created_at'=>'integer','updated_at'=>'integer'])->execute();
$db->createCommand()->createTable('game_orel', ['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer','kon'=>'decimal(18,5)','type'=>'integer','hod'=>'integer','created_at'=>'integer','updated_at'=>'integer'])->execute();
$db->schema->refresh();
$reset();
$db->createCommand()->insert('credit_transfer', ['user_id'=>1,'user_buyer'=>0,'amount'=>3,'password'=>'fixture-code','created_at'=>time()])->execute();
$transferId=(int)$db->getLastInsertID();
$results=race(array_fill(0,8,['transfer-claim',2,$transferId,0]));
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $credit(2)===103.0,
    'parallel transfer claims pay once');
verifyDb($count('history_rating') === 1 && abs((float)$db->createCommand('SELECT rating FROM persone WHERE user_id=1')->queryScalar() - 10.006) < .000001,
    'parallel transfer claims award sender rating exactly once');
$db->createCommand()->insert('credit_transfer', ['user_id'=>1,'user_buyer'=>0,'amount'=>3,'password'=>'fixture-code','created_at'=>time()])->execute();
$transferId=(int)$db->getLastInsertID();
$results=race([['transfer-claim',2,$transferId,0],['transfer-cancel',1,$transferId,0]]);
verifyDb(count(array_filter($results,static function($value){return $value==='ok';}))===1 && $credit(1)+$credit(2)===206.0,
    'transfer claim versus cancel has one consistent payout');
$db->createCommand()->update('user',['username'=>'bot'],['id'=>3])->execute();
$db->createCommand()->update('persone',['credit'=>1000],['user_id'=>3])->execute();
$results=race(array_fill(0,4,['bot',3,0,0]));
verifyDb($count('game_duel')===39 && $count('game_orel')===39 && $credit(3)===846.0,
    'concurrent cron replenishes each game plan once with correct debit');

$db->createCommand()->createTable('game_saper', ['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer','kon'=>'decimal(18,5)'])->execute();
$db->schema->refresh();
foreach (['orel', 'saper', 'duel', 'five'] as $kind) {
    $reset();
    $table = 'game_' . $kind;
    $db->createCommand()->delete($table)->execute();
    $currency = $kind === 'saper' ? 'balance' : 'credit';
    $db->createCommand()->update('persone', [$currency => 80], ['user_id' => 1])->execute();
    $ids = [];
    foreach ([1, 2] as $i) {
        $db->createCommand()->insert($table, array_merge(['user_id' => 1, 'user_gamer' => 0, 'kon' => 10], $kind === 'five' ? ['status' => 'free'] : []))->execute();
        $ids[] = (int)$db->getLastInsertID();
    }
    race([['delete-' . $kind, 1, $ids[0], 0], ['bulk-' . $kind, 1, 0, 0], ['bulk-' . $kind, 1, 0, 0]]);
    verifyDb($count($table) === 0 && (float)$db->createCommand('SELECT ' . $currency . ' FROM persone WHERE user_id=1')->queryScalar() === 100.0
        && $count('history_balance') === 2, $kind . ' simultaneous single and bulk deletion refund each stake once');
    $db->createCommand()->update('persone', [$currency => 90], ['user_id' => 1])->execute();
    $db->createCommand()->insert($table, array_merge(['user_id' => 1, 'user_gamer' => 0, 'kon' => 10], $kind === 'five' ? ['status' => 'free'] : []))->execute();
    $id = (int)$db->getLastInsertID();
    $results = race([['delete-' . $kind, 1, $id, 0], ['join-' . $kind, 2, $id, 0]]);
    $creator = (float)$db->createCommand('SELECT ' . $currency . ' FROM persone WHERE user_id=1')->queryScalar();
    $player = (float)$db->createCommand('SELECT ' . $currency . ' FROM persone WHERE user_id=2')->queryScalar();
    verifyDb(count(array_filter($results, static function ($value) { return $value === 'ok'; })) === 1
        && (($count($table) === 0 && $creator === 100.0 && $player === 100.0) || ($count($table) === 1 && $creator === 90.0 && $player === 90.0)),
        $kind . ' participation and deletion cannot both succeed');
}

// Validate the two pending conversion migrations on representative Cyrillic text.
$db->createCommand()->createTable('forum_theme',['id'=>'pk','title'=>'string'])->execute();
$db->createCommand()->createTable('forum_comment',['id'=>'pk','comment'=>'text'])->execute();
$text='Привет, яблоки и ёж';
$db->createCommand()->insert('forum_theme',['title'=>$text])->execute();
$db->createCommand()->insert('forum_comment',['comment'=>$text])->execute();
(new \console\migrations\m260911_120000_convert_forum_text_to_utf8(['db'=>$db]))->up();
(new \console\migrations\m260911_130000_normalize_forum_utf8(['db'=>$db]))->up();
verifyDb($db->createCommand('SELECT title FROM forum_theme')->queryScalar()===$text
    && $db->createCommand('SELECT comment FROM forum_comment')->queryScalar()===$text,'UTF-8 migrations preserve Cyrillic text');
echo 'Target database checks passed: '.$db->driverName.PHP_EOL;
