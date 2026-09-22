<?php
// Included by the isolated routing suite; no live database or network.
$batch = $db->beginTransaction();
try {
    $db->createCommand()->delete('forum_comment')->execute();
    foreach ([[100, 5], [101, 1], [102, 8]] as $message) {
        $db->createCommand()->insert('forum_comment', ['id' => $message[0], 'theme_id' => 1, 'user_id' => 2,
            'comment' => 'Message', 'active' => 1, 'created_at' => $message[1]])->execute();
    }
    $query = ['filter' => ['theme_id' => '1'], 'exclude_starter' => '1', 'envelope' => '1', 'per-page' => '1'];
    $first = dispatch('GET', 'v1/forum-comment', false, $query)[1];
    $second = dispatch('GET', 'v1/forum-comment', false, $query + ['page' => '2'])[1];
    routeCheck($first['_meta']['totalCount'] === 2 && (int)$first['items'][0]['id'] === 102 && (int)$second['items'][0]['id'] === 100,
        'starter exclusion precedes pagination and uses oldest visible date');
    routeCheck(dispatch('GET', 'v1/forum-comment', false, ['filter' => ['theme_id' => '1'], 'envelope' => '1'])[1]['_meta']['totalCount'] === 3,
        'legacy comments list remains complete');

    $db->createCommand()->delete('history_rating')->execute();
    $db->createCommand()->update('persone', ['rating' => 0, 'credit' => 100], ['user_id' => 2])->execute();
    foreach ([9901, 9902, 9903] as $id) $db->createCommand()->insert('credit_transfer', [
        'id' => $id, 'user_id' => 2, 'user_buyer' => 0, 'amount' => 50, 'password' => 'rating-test', 'created_at' => 1,
    ])->execute();
    routeCheck(dispatch('PUT', 'v1/transfer/9901', true, [], ['password' => 'wrong'])[0] === 422
        && (int)$db->createCommand('SELECT COUNT(*) FROM history_rating')->queryScalar() === 0, 'wrong transfer hash gives no rating');
    $recipientRating = (float)$db->createCommand('SELECT rating FROM persone WHERE user_id=1')->queryScalar();
    routeCheck(dispatch('PUT', 'v1/transfer/9901', true, [], ['password' => 'rating-test'])[0] === 200, 'transfer with zero sender rating succeeds');
    routeCheck((float)$db->createCommand('SELECT rating FROM persone WHERE user_id=2')->queryScalar() === 0.0
        && (float)$db->createCommand('SELECT rating FROM persone WHERE user_id=1')->queryScalar() === $recipientRating,
        'topic 68: a sender without the rating advantage receives no rating');
    dispatch('PUT', 'v1/transfer/9901', true, [], ['password' => 'rating-test']);
    routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM history_rating')->queryScalar() === 0, 'repeat transfer claim never repeats rating');
    $senderRating = $recipientRating + 50;
    $db->createCommand()->update('persone', ['rating' => $senderRating], ['user_id' => 2])->execute();
    $db->createCommand()->update('credit_transfer', ['updated_at' => time() - 8 * 86400], ['user_buyer' => 2])->execute();
    $reward = round(1 / $senderRating, 5);
    dispatch('PUT', 'v1/transfer/9902', true, [], ['password' => 'rating-test']);
    routeCheck(abs((float)$db->createCommand('SELECT rating FROM persone WHERE user_id=2')->queryScalar() - ($senderRating + $reward)) < .000001,
        'eligible transfer uses the updated sender rating');
    $creditBefore = $db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar();
    $db->pdo->exec("CREATE TRIGGER reject_rating BEFORE INSERT ON history_rating BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    try {
        dispatch('PUT', 'v1/transfer/9903', true, [], ['password' => 'rating-test']);
        throw new RuntimeException('Rating history failure must abort transfer.');
    } catch (\yii\db\Exception $expected) {
        routeCheck((int)$db->createCommand('SELECT user_buyer FROM credit_transfer WHERE id=9903')->queryScalar() === 0
            && $db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === $creditBefore, 'rating failure rolls back transfer and credits');
    }
    $db->pdo->exec('DROP TRIGGER reject_rating');

    $db->createCommand('CREATE TABLE game_five_hod (id INTEGER PRIMARY KEY, game_five_id INTEGER)')->execute();
    foreach (['orel', 'saper', 'duel', 'five'] as $kind) {
        $table = 'game_' . $kind;
        $currency = $kind === 'saper' ? 'balance' : 'credit';
        $db->createCommand()->delete($table)->execute();
        $db->createCommand()->delete('history_balance')->execute();
        $db->createCommand()->update('persone', [$currency => 100], ['user_id' => 1])->execute();
        foreach ([[1, 1, 0, 2], [2, 1, 0, 3], [3, 1, 0, 4], [4, 2, 0, 5], [5, 1, 2, 6]] as $row) {
            $game = ['id' => $row[0], 'user_id' => $row[1], 'user_gamer' => $row[2], 'kon' => $row[3]];
            if ($kind === 'five') $game['status'] = str_pad($row[2] ? 'play' : 'free', 50);
            $db->createCommand()->insert($table, $game)->execute();
        }
        if ($kind === 'five') foreach ([1, 2, 3, 4, 5] as $id) $db->createCommand()->insert('game_five_hod', ['game_five_id' => $id])->execute();
        routeCheck(dispatch('DELETE', 'v1/' . $kind . '/remove')[0] === 401, $kind . ' bulk deletion requires authentication');
        routeCheck(dispatch('GET', 'v1/' . $kind . '/remove', true)[0] >= 400, $kind . ' GET never deletes games');
        routeCheck(dispatch('DELETE', 'v1/' . $kind . '/4', true)[0] === 403 && dispatch('DELETE', 'v1/' . $kind . '/5', true)[0] === 409,
            $kind . ' cannot delete another player or started game');
        routeCheck(dispatch('DELETE', 'v1/' . $kind . '/1', true)[0] === 204 && dispatch('DELETE', 'v1/' . $kind . '/1', true)[0] === 404,
            $kind . ' single cancellation refunds once');
        $removed = dispatch('DELETE', 'v1/' . $kind . '/remove', true);
        routeCheck($removed[0] === 200 && $removed[1]['deleted'] === 2 && (float)$removed[1]['refunded'] === 7.0
            && (float)$db->createCommand('SELECT ' . $currency . ' FROM persone WHERE user_id=1')->queryScalar() === 109.0,
            $kind . ' bulk deletion refunds exact remaining stakes in the correct currency');
        routeCheck(dispatch('DELETE', 'v1/' . $kind . '/remove', true)[1]['deleted'] === 0
            && (int)$db->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar() === 2
            && (int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 3, $kind . ' repeat bulk is a no-op and preserves started/foreign games');
        if ($kind === 'five') routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM game_five_hod')->queryScalar() === 2, 'five cancellation removes only corresponding rounds');
        $db->createCommand()->insert($table, array_merge(['id' => 10, 'user_id' => 1, 'user_gamer' => 0, 'kon' => 10], $kind === 'five' ? ['status' => 'free'] : []))->execute();
        $db->pdo->exec("CREATE TRIGGER reject_refund BEFORE INSERT ON history_balance BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        try {
            dispatch('DELETE', 'v1/' . $kind . '/remove', true);
            throw new RuntimeException('Refund history failure must abort cancellation.');
        } catch (\yii\db\Exception $expected) {
            routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE id=10')->queryScalar() === 1
                && (float)$db->createCommand('SELECT ' . $currency . ' FROM persone WHERE user_id=1')->queryScalar() === 109.0, $kind . ' failed refund restores game and balance');
        }
        $db->pdo->exec('DROP TRIGGER reject_refund');
    }
} finally { $batch->rollBack(); }
