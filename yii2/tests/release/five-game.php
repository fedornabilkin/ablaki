<?php

defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);

class FiveIdentity extends \api\modules\v1\models\User
{
    public static function findIdentityByAccessToken($token, $type = null)
    {
        return preg_match('/^five-test-([123])$/D', $token, $match) ? self::findOne((int)$match[1]) : null;
    }
}
function checkFive($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
function fiveRequest(string $method, string $path, int $user = 1, array $body = [], array $query = []): array
{
    $app = Yii::$app;
    $app->user->setIdentity(null);
    $app->response->setStatusCode(200);
    $_SERVER['REQUEST_METHOD'] = $method;
    $app->request->headers->removeAll();
    $app->request->headers->set('Accept', 'application/json');
    if ($user) $app->request->headers->set('Authorization', 'Bearer five-test-' . $user);
    $app->request->setQueryParams($query);
    $app->request->setBodyParams($body);
    $app->request->setPathInfo('v1/five' . $path);
    $route = $app->urlManager->parseRequest($app->request);
    if ($route === false) return [404, null];
    try {
        $data = $app->runAction($route[0], $route[1]);
        return [$app->response->statusCode, $data];
    } catch (\yii\web\HttpException $error) {
        return [$error->statusCode, null];
    }
}
$api = require dirname(__DIR__, 2) . '/api/config/main.php';
$app = new \yii\web\Application([
    'id' => 'five-game-test', 'basePath' => dirname(__DIR__, 2), 'timeZone' => 'Europe/Moscow',
    'runtimePath' => sys_get_temp_dir() . '/ablakin-five-' . uniqid(),
    'modules' => array_merge($api['modules'], ['user' => ['class' => \dektrium\user\Module::class]]), 'container' => $api['container'],
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['class' => \yii\web\Request::class, 'cookieValidationKey' => 'test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
        'response' => ['class' => \yii\web\Response::class, 'format' => \yii\web\Response::FORMAT_JSON],
        'user' => ['class' => \yii\web\User::class, 'identityClass' => FiveIdentity::class, 'enableSession' => false],
        'urlManager' => ['enablePrettyUrl' => true, 'enableStrictParsing' => true, 'showScriptName' => false,
            'rules' => require dirname(__DIR__, 2) . '/common/modules/games/config/urlRules.php'],
        'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => '@common/messages']]],
    ],
]);
$db = $app->db;
foreach ([
    'user' => 'id INTEGER PRIMARY KEY, username TEXT, email TEXT, created_at INTEGER, last_login_at INTEGER',
    'persone' => 'id INTEGER PRIMARY KEY, user_id INTEGER, credit NUMERIC, balance NUMERIC, rating NUMERIC, bonus_count INTEGER, refovod INTEGER, description TEXT',
    'game_five' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_gamer INTEGER, kon NUMERIC, status TEXT, user_amount INTEGER, gamer_amount INTEGER, created_at INTEGER, updated_at INTEGER',
    'game_five_hod' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, game_five_id INTEGER, user_id INTEGER, user_gamer INTEGER, user_ball INTEGER, gamer_ball INTEGER, status TEXT, user_amount INTEGER, gamer_amount INTEGER, created_at INTEGER',
    'history_balance' => 'id INTEGER PRIMARY KEY, user_id INTEGER, balance NUMERIC, credit NUMERIC, balance_up NUMERIC, credit_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER',
    'history_rating' => 'id INTEGER PRIMARY KEY, user_id INTEGER, rating NUMERIC, rating_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER',
    'comission' => 'id INTEGER PRIMARY KEY, type TEXT, amount NUMERIC, created_at INTEGER',
    'forum_comment_gift' => 'id INTEGER PRIMARY KEY, user_id INTEGER',
] as $table => $columns) $db->createCommand('CREATE TABLE ' . $table . ' (' . $columns . ')')->execute();
$db->createCommand("INSERT INTO user VALUES (1,'Creator','private1',1,1),(2,'Player','private2',1,1),(3,'Other','private3',1,1)")->execute();
$db->createCommand("INSERT INTO persone VALUES (1,1,100,50,1,0,0,''),(2,2,100,50,1,0,0,''),(3,3,100,50,1,0,0,'')")->execute();
$balance = static function ($id) use ($db) { return (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=:id', [':id'=>$id])->queryScalar(); };
$count = static function ($table) use ($db) { return (int)$db->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar(); };
checkFive(fiveRequest('GET', '', 0)[0] === 401, 'game lists require authentication');
checkFive(fiveRequest('POST', '', 1, ['kon'=>10, 'ball'=>6])[0] === 400, 'invalid first move rejected');
list($status, $game) = fiveRequest('POST', '', 1, ['kon'=>10, 'ball'=>4]);
checkFive($status === 201 && $balance(1) === 90.0 && $game['status'] === 'free', 'creating a game reserves one stake');
$id = (int)$game['id'];
$round = (int)$game['last_hod']['id'];
$db->createCommand()->update('game_five', ['status' => str_pad('free', 50)], ['id' => $id])->execute();
$db->createCommand()->update('game_five_hod', ['status' => str_pad('wait', 50)], ['id' => $round])->execute();
checkFive($game['last_hod']['user_ball'] === 4 && $game['winner_amount'] === 19.0, 'creator sees own hidden move and server payout after commission');
$other = fiveRequest('GET', '/' . $id, 2)[1];
checkFive(!isset($other['last_hod']['user_ball']) && !isset($other['creator']['email'], $other['creator']['person']['credit']), 'opponent sees neither hidden move nor private creator fields');
checkFive(fiveRequest('POST', '/play/' . $id, 1, ['ball'=>5, 'round_id'=>$round])[0] === 403, 'creator cannot join own game');
list($status, $move) = fiveRequest('POST', '/play/' . $id, 2, ['ball'=>5, 'round_id'=>$round]);
checkFive($status === 200 && $move['game']['user_points'] === 9 && $move['game']['turn'] === 'user' && $balance(2) === 90.0, 'joining reserves opponent stake and resolves first round');
checkFive(fiveRequest('POST', '/play/' . $id, 2, ['ball'=>5, 'round_id'=>$round])[0] === 409 && $count('history_balance') === 2, 'repeated response does not debit or score twice');
checkFive(fiveRequest('POST', '/play/' . $id, 3, ['ball'=>1, 'round_id'=>$round])[0] === 403, 'third player cannot enter an active game');
checkFive(fiveRequest('DELETE', '/' . $id, 1)[0] === 409, 'active game cannot be cancelled');
for ($i=0; $i<2; $i++) {
    list($status, $opened) = fiveRequest('POST', '/play/' . $id, 1, ['ball'=>4, 'round_id'=>$round]);
    $nextRound = (int)$opened['game']['last_hod']['id'];
    checkFive($status === 200 && $nextRound !== $round && $opened['game']['turn'] === 'gamer', 'creator opens a new hidden round');
    checkFive(fiveRequest('POST', '/play/' . $id, 2, ['ball'=>5, 'round_id'=>$round])[0] === 409, 'late response for previous round is rejected');
    checkFive(!isset(fiveRequest('GET', '/' . $id, 2)[1]['last_hod']['user_ball']), 'polling never reveals a pending opponent move');
    list($status, $move) = fiveRequest('POST', '/play/' . $id, 2, ['ball'=>5, 'round_id'=>$nextRound]);
    checkFive($status === 200, 'opponent answers current round');
    $round = $nextRound;
}
checkFive($move['game']['status'] === 'user' && $move['game']['user_points'] === 27 && $move['game']['turn'] === null, 'game ends on reaching at least 21 points');
checkFive($balance(1) === 109.0 && $balance(2) === 90.0 && $count('history_balance') === 3 && $count('history_rating') === 1
    && (float)$db->createCommand('SELECT SUM(amount) FROM comission')->queryScalar() === 1.0, 'winner paid exactly once and commission/ratings recorded');
checkFive(fiveRequest('POST', '/play/' . $id, 2, ['ball'=>5, 'round_id'=>$round])[0] === 409 && $balance(1) === 109.0, 'finished game cannot be paid again');
list($status, $history) = fiveRequest('GET', '/history', 2, [], ['envelope'=>1, 'period'=>'today']);
checkFive($status === 200 && $history['_meta']['totalCount'] === 1 && $history['items'][0]['status'] === 'user', 'finished game appears in participant history');

list(, $free) = fiveRequest('POST', '', 1, ['kon'=>5, 'ball'=>2]);
$cancelId = (int)$free['id'];
checkFive(fiveRequest('DELETE', '/' . $cancelId, 2)[0] === 403, 'opponent cannot cancel a free game');
$before1=$balance(1); $before2=$balance(2); $beforeHistory=$count('history_balance');
$db->pdo->exec("CREATE TRIGGER reject_ledger BEFORE INSERT ON history_balance BEGIN SELECT RAISE(ABORT, 'test ledger failure'); END");
foreach (['join','cancel'] as $operation) {
    try {
        if ($operation === 'join') fiveRequest('POST', '/play/' . $cancelId, 2, ['ball'=>3, 'round_id'=>$free['last_hod']['id']]);
        else fiveRequest('DELETE', '/' . $cancelId, 1);
        throw new RuntimeException('Ledger failure accepted');
    } catch (\yii\db\Exception $expected) {}
    checkFive($balance(1)===$before1 && $balance(2)===$before2 && $count('history_balance')===$beforeHistory
        && fiveRequest('GET', '/' . $cancelId, 1)[1]['status']==='free', $operation . ' failure rolls back game and account changes');
}
$db->createCommand('DROP TRIGGER reject_ledger')->execute();
checkFive(fiveRequest('DELETE', '/' . $cancelId, 1)[0] === 204 && $balance(1) === 109.0, 'free game cancellation returns exactly its stake');
checkFive(fiveRequest('DELETE', '/' . $cancelId, 1)[0] === 404 && $balance(1) === 109.0, 'repeated cancellation cannot refund again');
for ($i=0; $i<3; $i++) fiveRequest('POST', '', 1, ['kon'=>1, 'ball'=>3]);
list($status, $available) = fiveRequest('GET', '', 2, [], ['envelope'=>1, 'page'=>2, 'per-page'=>1, 'q'=>'creator']);
checkFive($status === 200 && count($available['items'])===1 && $available['_meta']['totalCount']===3 && $available['_meta']['pageCount']===3, 'available list searches and paginates on server');
checkFive(fiveRequest('GET', '/my', 3, [], ['envelope'=>1])[1]['_meta']['totalCount']===0, 'my list excludes other players');
$db->createCommand('UPDATE persone SET credit=0 WHERE user_id=3')->execute();
checkFive(fiveRequest('POST', '', 3, ['kon'=>1, 'ball'=>1])[0] === 409, 'insufficient funds rejected before game creation');

// The opponent can also win; commission failure must roll back the final round and payout.
$before1=$balance(1); $before2=$balance(2);
list(, $game2) = fiveRequest('POST', '', 1, ['kon'=>10, 'ball'=>5]);
$id2=(int)$game2['id']; $round2=(int)$game2['last_hod']['id'];
fiveRequest('POST', '/play/' . $id2, 2, ['ball'=>4, 'round_id'=>$round2]);
for ($i=0; $i<2; $i++) {
    $opened = fiveRequest('POST', '/play/' . $id2, 1, ['ball'=>5, 'round_id'=>$round2])[1];
    $round2=(int)$opened['game']['last_hod']['id'];
    if ($i===1) {
        $db->pdo->exec("CREATE TRIGGER reject_commission BEFORE INSERT ON comission BEGIN SELECT RAISE(ABORT, 'test commission failure'); END");
        try { fiveRequest('POST', '/play/' . $id2, 2, ['ball'=>4, 'round_id'=>$round2]); throw new RuntimeException('Failed commission accepted'); }
        catch (\yii\db\Exception $expected) {}
        $unchanged=fiveRequest('GET', '/' . $id2, 2)[1];
        checkFive($unchanged['status']==='play' && $unchanged['gamer_points']===18 && $unchanged['last_hod']['status']==='wait'
            && $balance(2)===$before2-10, 'commission failure rolls back final score, round, rating and payout');
        $db->createCommand('DROP TRIGGER reject_commission')->execute();
    }
    $result=fiveRequest('POST', '/play/' . $id2, 2, ['ball'=>4, 'round_id'=>$round2]);
}
checkFive($result[1]['game']['status']==='gamer' && $balance(1)===$before1-10 && $balance(2)===$before2+9, 'opponent winner receives net payout after successful retry');

// Exhaustively verify all 25 combinations of round rules.
foreach (range(1,5) as $a) foreach (range(1,5) as $b) {
    $hod = new \common\modules\games\models\GameFiveHod(['user_ball'=>$a, 'gamer_ball'=>$b]);
    $winner = $a===$b ? 'draw' : (abs($a-$b)===1 ? ($a<$b?'user':'gamer') : ($a>$b?'user':'gamer'));
    $amount = $a===$b ? 0 : (abs($a-$b)===1 ? $a+$b : abs($a-$b));
    checkFive($hod->getWinnerStatus()===$winner && $hod->getWinAmount()===$amount, 'round rules ' . $a . ':' . $b);
}
echo "Five game REST cycle passed on disposable SQLite; PostgreSQL/MySQL locks require target-engine validation.\n";
