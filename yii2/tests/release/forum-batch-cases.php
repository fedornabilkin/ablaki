<?php
// Included by frontend-api-routing.php; only its disposable SQLite and real routes.
$batch = $db->beginTransaction();
try {
    $db->createCommand()->update('persone', ['rating' => 12.34, 'credit' => 100], ['user_id' => 2])->execute();
    list($status, $themes) = dispatch('GET', 'v1/forum-theme', false, ['envelope' => '1']);
    $matching = array_values(array_filter($themes['items'], static function ($theme) { return (int)$theme['id'] === 1; }));
    $author = $matching[0]['user'];
    routeCheck($status === 200 && (int)$author['id'] === 2 && (float)$author['person']['rating'] === 12.34
        && !isset($author['email']) && !isset($author['person']['credit']), 'theme author has real rating and public profile');
    list($status, $topic) = dispatch('GET', 'v1/forum-theme/1', false, ['expand' => 'first_comment', 'page' => 99]);
    routeCheck($status === 200 && (int)$topic['first_comment']['id'] === 1 && $topic['first_comment']['comment'] === 'Message', 'starter ignores pagination and hidden comments');
    $db->createCommand()->update('forum_theme', ['user_id' => null], ['id' => 1])->execute();
    $fallback = dispatch('GET', 'v1/forum-theme/1')[1]['user'];
    routeCheck((int)$fallback['id'] === 2 && (float)$fallback['person']['rating'] === 12.34, 'legacy theme uses first active author');
    $db->createCommand()->update('persone', ['credit' => 100], ['user_id' => 1])->execute();
    routeCheck(dispatch('POST', 'v1/transfer', true, [], ['amount' => 2, 'count' => 2])[0] === 201, 'create accepts whole credits');
    $created = $db->createCommand('SELECT * FROM credit_transfer WHERE user_id=1 ORDER BY id DESC LIMIT 2')->queryAll();
    routeCheck(count($created) === 2 && strlen($created[0]['password']) === 32 && $created[0]['password'] !== $created[1]['password']
        && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 96.0, 'new codes unique, long, and reserve exact amount');
    routeCheck(dispatch('PUT', 'v1/transfer/' . $created[0]['id'], true, [], ['password' => $created[0]['password']])[0] === 403, 'cannot receive own transfer');
    $db->createCommand()->insert('credit_transfer', ['id' => 9001, 'user_id' => 2, 'user_buyer' => 0, 'amount' => 3, 'password' => 'old-code', 'created_at' => 1])->execute();
    foreach ([[], ['password' => 'wrong'], ['password' => ['old-code']]] as $invalid) {
        routeCheck(dispatch('PUT', 'v1/transfer/9001', true, [], $invalid)[0] === 422, 'missing or invalid code rejected');
    }
    routeCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 96.0, 'wrong claim preserves balance');
    list($status, $received) = dispatch('PUT', 'v1/transfer/9001', true, [], ['password' => 'old-code']);
    routeCheck($status === 200 && $received['password'] === null && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 99.0, 'legacy code pays without exposing recipient hash');
    routeCheck(dispatch('PUT', 'v1/transfer/9001', true, [], ['password' => 'old-code'])[0] === 422, 'repeated receiving rejected');
    routeCheck(dispatch('DELETE', 'v1/transfer/' . $created[0]['id'], true)[0] < 300, 'sender cancels unreceived transfer');
    routeCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 101.0, 'cancellation refunds exact amount');
    $before = (int)$db->createCommand('SELECT COUNT(*) FROM credit_transfer')->queryScalar();
    foreach ([['amount' => -1, 'count' => 1], ['amount' => 0.001, 'count' => 1], ['amount' => 1, 'count' => 101], ['amount' => 1000, 'count' => 1]] as $invalid) {
        routeCheck(dispatch('POST', 'v1/transfer', true, [], $invalid)[0] === 422, 'invalid transfer or insufficient funds rejected');
    }
    routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM credit_transfer')->queryScalar() === $before, 'invalid creates leave no transfers');
    $db->createCommand()->insert('credit_transfer', ['id' => 9002, 'user_id' => 2, 'user_buyer' => 0, 'amount' => 3, 'password' => 'rollback', 'created_at' => 1])->execute();
    $db->pdo->exec("CREATE TRIGGER reject_transfer_history BEFORE INSERT ON history_balance BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    try {
        dispatch('PUT', 'v1/transfer/9002', true, [], ['password' => 'rollback']);
        throw new RuntimeException('history failure must abort claim');
    } catch (\yii\db\Exception $expected) {
        routeCheck((int)$db->createCommand('SELECT user_buyer FROM credit_transfer WHERE id=9002')->queryScalar() === 0
            && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 101.0, 'failed history rolls back claim and balance');
    }
    $db->pdo->exec('DROP TRIGGER reject_transfer_history');
    $db->createCommand()->delete('game_duel')->execute();
    $db->createCommand()->update('user', ['username' => 'bot'], ['id' => 2])->execute();
    $db->createCommand()->update('persone', ['credit' => 1000], ['user_id' => 2])->execute();
    $db->createCommand()->delete('game_orel')->execute();
    $bot = new \common\services\game\GameCreateService();
    $bot->execute();
    $balance = (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=2')->queryScalar();
    routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM game_duel')->queryScalar() === 39
        && (int)$db->createCommand('SELECT COUNT(*) FROM game_orel')->queryScalar() === 39 && $balance === 846.0, 'cron creates both types with funded stakes');
    routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM game_duel WHERE u1 BETWEEN 1 AND 3 AND b1 BETWEEN 1 AND 3')->queryScalar() === 39, 'bot chooses valid strike and block');
    $bot->execute();
    routeCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=2')->queryScalar() === $balance, 'repeat cron does not duplicate or debit');
    $db->createCommand()->delete('game_duel', ['id' => 1])->execute();
    $db->createCommand()->update('persone', ['credit' => 0], ['user_id' => 2])->execute();
    $bot->execute();
    routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM game_duel')->queryScalar() === 38, 'empty balance never creates unfunded games');
    $db->createCommand()->update('user', ['username' => 'no-bot'], ['id' => 2])->execute();
    $bot->execute();
    routeCheck(true, 'missing bot is a safe no-op');
} finally { $batch->rollBack(); }
