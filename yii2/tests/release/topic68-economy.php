<?php
// Disposable SQLite only; no application configuration, credentials or live balances.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);

use common\models\user\User;
use common\models\user\Person;
use common\modules\games\apiControllers\OrelController;
use common\modules\games\models\GameDuel;
use common\modules\games\service\DuelService;
use common\modules\exchange\api\models\CreditTransfer;
use common\modules\exchange\service\TransferService;
use common\services\user\PrizeFundService;
use yii\db\Query;

$app = new \yii\console\Application([
    'id' => 'topic68-economy', 'basePath' => dirname(__DIR__, 2),
    'modules' => ['user' => ['class' => \dektrium\user\Module::class]],
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['class' => \yii\web\Request::class, 'cookieValidationKey' => 'test-only', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
        'user' => ['class' => \yii\web\User::class, 'identityClass' => User::class, 'enableSession' => false],
        'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => '@common/messages']]],
    ],
]);
$db = $app->db;
$tables = [
    'user' => ['id'=>'pk','username'=>'string','created_at'=>'integer','last_login_at'=>'integer'],
    'persone' => ['id'=>'pk','user_id'=>'integer','credit'=>'decimal(18,5)','balance'=>'decimal(18,5)','rating'=>'decimal(18,5)','bonus_count'=>'integer NOT NULL DEFAULT 0'],
    'history_balance' => ['id'=>'pk','user_id'=>'integer','balance'=>'decimal(18,5)','credit'=>'decimal(18,5)','balance_up'=>'decimal(18,5)','credit_up'=>'decimal(18,5)','type'=>'string','comment'=>'string','created_at'=>'integer'],
    'history_rating' => ['id'=>'pk','user_id'=>'integer','rating'=>'decimal(18,5)','rating_up'=>'decimal(18,5)','type'=>'string','comment'=>'string','created_at'=>'integer'],
    'comission' => ['id'=>'pk','type'=>'string','amount'=>'decimal(18,5)','created_at'=>'integer'],
    'credit_transfer' => ['id'=>'pk','user_id'=>'integer','user_buyer'=>'integer','amount'=>'integer','password'=>'string','created_at'=>'integer','updated_at'=>'integer'],
    'game_orel' => ['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer','kon'=>'decimal(18,5)','type'=>'integer','hod'=>'integer','created_at'=>'integer','updated_at'=>'integer'],
    'game_duel' => ['id'=>'pk','user_id'=>'integer','user_gamer'=>'integer','kon'=>'decimal(18,5)','u1'=>'integer','b1'=>'integer','u2'=>'integer','b2'=>'integer','created_at'=>'integer','updated_at'=>'integer'],
];
foreach ($tables as $table => $columns) $db->createCommand()->createTable($table, $columns)->execute();
foreach ([1,2,3] as $id) {
    $db->createCommand()->insert('user', ['id'=>$id,'username'=>'Player'.$id])->execute();
    $db->createCommand()->insert('persone', ['id'=>$id,'user_id'=>$id,'credit'=>100,'balance'=>0,'rating'=>10])->execute();
}
function checkEconomy(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
$credit = static function ($id) use ($db) { return (float)(new Query())->from('persone')->select('credit')->where(['user_id'=>$id])->scalar($db); };
$count = static function ($table) use ($db) { return (int)(new Query())->from($table)->count('*', $db); };
$fund = new PrizeFundService($db);
$app->user->setIdentity(User::findOne(2));
foreach ([1,2] as $side) {
    $db->createCommand()->insert('game_orel', ['user_id'=>1,'user_gamer'=>0,'kon'=>1,'type'=>1,'hod'=>0,'created_at'=>time()])->execute();
    $id = (int)$db->getLastInsertID();
    $before = $fund->summary(1)['tomorrow'];
    $app->request->setBodyParams(['hod'=>$side]);
    (new OrelController('orel', $app))->actionPlay($id);
    checkEconomy(abs($fund->summary(1)['tomorrow'] - $before - .1) < .00001, 'coin win/loss records the 0.1 Cr commission in tomorrow fund');
    try { (new OrelController('orel', $app))->actionPlay($id); throw new LogicException('Repeated play accepted'); }
    catch (\yii\base\UserException $expected) {}
    checkEconomy(abs($fund->summary(1)['tomorrow'] - $before - .1) < .00001, 'repeated play cannot increase the fund twice');
}
foreach ([[1,2], [3,1], [1,3]] as $move) {
    $db->createCommand()->insert('game_duel', ['user_id'=>1,'user_gamer'=>0,'kon'=>1,'u1'=>1,'b1'=>2,'u2'=>0,'b2'=>0,'created_at'=>time()])->execute();
    $game = GameDuel::findOne((int)$db->getLastInsertID());
    $game->u2 = $move[0]; $game->b2 = $move[1];
    $before = $fund->summary(1)['tomorrow'];
    (new DuelService())->play($game, Person::findOne(2));
    $commission = $game->getWinnerStatus() === GameDuel::STATUS_DRAW ? 0 : .1;
    checkEconomy(abs($fund->summary(1)['tomorrow'] - $before - $commission) < .00001, 'duel commission enters fund only when there is a winner');
}

$service = new TransferService();
// A rejected commission insert must roll back the entire game settlement.
$db->createCommand()->insert('game_orel', ['user_id'=>1,'user_gamer'=>0,'kon'=>1,'type'=>1,'hod'=>0,'created_at'=>time()])->execute();
$failedGame = (int)$db->getLastInsertID();
$beforeCredit = $credit(2); $beforeFund = $fund->summary(1)['tomorrow'];
$beforeHistory = $count('history_balance');
$rejectCommission = static function ($event) { $event->isValid = false; };
\yii\base\Event::on(\common\models\Commission::class, \yii\db\ActiveRecord::EVENT_BEFORE_INSERT, $rejectCommission);
try {
    $app->request->setBodyParams(['hod'=>1]);
    (new OrelController('orel', $app))->actionPlay($failedGame);
    throw new LogicException('Commission failure was ignored');
} catch (RuntimeException $expected) {
    checkEconomy($credit(2) === $beforeCredit && $fund->summary(1)['tomorrow'] === $beforeFund
        && $count('history_balance') === $beforeHistory
        && (int)(new Query())->from('game_orel')->select('user_gamer')->where(['id'=>$failedGame])->scalar() === 0,
        'rejected commission rolls back game, payment and history');
} finally {
    \yii\base\Event::off(\common\models\Commission::class, \yii\db\ActiveRecord::EVENT_BEFORE_INSERT, $rejectCommission);
}
$app->user->setIdentity(User::findOne(1));
$db->createCommand()->update('persone', ['credit'=>12, 'rating'=>60], ['user_id'=>1])->execute();
$result = $service->create(new CreditTransfer(['amount'=>5,'count'=>10]));
checkEconomy($result['created'] === 2 && $result['requested'] === 10 && $result['total'] === 10.0 && $credit(1) === 2.0, 'batch reserves only the affordable whole transfers');
checkEconomy($count('credit_transfer') === 2 && count(array_unique((new Query())->from('credit_transfer')->select('password')->column())) === 2, 'each batch transfer has its own code');
$before = $count('history_balance');
try { $service->create(new CreditTransfer(['amount'=>5,'count'=>2])); throw new LogicException('Insufficient batch accepted'); }
catch (\yii\web\UnprocessableEntityHttpException $expected) {}
checkEconomy($credit(1) === 2.0 && $count('credit_transfer') === 2 && $count('history_balance') === $before, 'unaffordable batch writes nothing');

foreach ([[60,10,null,true], [59.99,10,null,false], [60,10,time()-60,false], [60,10,time()-7*86400-10,true]] as $case) {
    $tx = $db->beginTransaction();
    $db->createCommand()->update('persone', ['rating'=>$case[0]], ['user_id'=>1])->execute();
    $db->createCommand()->update('persone', ['rating'=>$case[1]], ['user_id'=>2])->execute();
    if ($case[2] !== null) $db->createCommand()->insert('credit_transfer', ['user_id'=>3,'user_buyer'=>1,'amount'=>1,'password'=>'old','updated_at'=>$case[2]])->execute();
    $db->createCommand()->insert('credit_transfer', ['user_id'=>1,'user_buyer'=>0,'amount'=>3,'password'=>'claim-code','created_at'=>time()])->execute();
    $model = CreditTransfer::findOne((int)$db->getLastInsertID());
    $app->user->setIdentity(User::findOne(2));
    $before = $count('history_rating'); $recipientCredit = $credit(2);
    $service->confirm($model, 'claim-code');
    checkEconomy($credit(2) === $recipientCredit + 3 && $count('history_rating') === $before + ($case[3] ? 1 : 0), 'transfer rating requires a 50-point advantage and no receipts in seven days');
    if ($case[3]) {
        $row = (new Query())->from('history_rating')->orderBy(['id'=>SORT_DESC])->one();
        checkEconomy(abs((float)$row['rating'] - 60.001) < .000001 && abs((float)$row['rating_up'] - .001) < .000001, 'transfer history records the resulting sender rating');
    }
    try { $service->confirm($model, 'claim-code'); throw new LogicException('Double transfer accepted'); }
    catch (\yii\web\UnprocessableEntityHttpException $expected) {}
    $tx->rollBack();
}

$app->user->setIdentity(User::findOne(1));
$db->createCommand()->update('persone', ['credit'=>20], ['user_id'=>1])->execute();
$db->pdo->exec("CREATE TRIGGER fail_transfer BEFORE INSERT ON credit_transfer BEGIN SELECT RAISE(ABORT, 'injected transfer failure'); END");
$before = $count('history_balance');
try { $service->create(new CreditTransfer(['amount'=>5,'count'=>3])); throw new LogicException('Failure expected'); }
catch (\yii\db\Exception $expected) {}
checkEconomy($credit(1) === 20.0 && $count('history_balance') === $before, 'batch failure rolls back balance and ledger');
echo "Topic 68 economy checks passed.\n";
