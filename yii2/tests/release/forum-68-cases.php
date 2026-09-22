<?php
// Included by the isolated routing suite.
$batch = $db->beginTransaction();
try {
    $db->createCommand('CREATE TABLE game_five_hod (id INTEGER PRIMARY KEY, game_five_id INTEGER)')->execute();
    $db->schema->refresh();
    foreach (['orel', 'saper', 'duel', 'five'] as $kind) {
        $table = 'game_' . $kind;
        $db->createCommand()->delete($table)->execute();
        foreach ([[1,1,0,3], [2,2,0,5], [3,2,0,5], [4,2,0,10], [5,1,2,99]] as $row) {
            $values = ['id'=>$row[0],'user_id'=>$row[1],'user_gamer'=>$row[2],'kon'=>$row[3],'created_at'=>time()];
            if ($kind === 'five') $values['status'] = $row[2] ? 'play' : 'free';
            $db->createCommand()->insert($table, $values)->execute();
        }
        $path = 'v1/' . $kind . '/stakes';
        routeCheck(dispatch('GET', $path)[0] === 401, $kind . ' stake groups require authentication');
        $groups = dispatch('GET', $path, true, ['filter'=>['kon'=>10]])[1];
        routeCheck(array_map('intval', array_column($groups, 'kon')) === [5,10]
            && array_map('intval', array_column($groups, 'count')) === [2,1], $kind . ' available grouping excludes own and started games, ignoring selected stake');
        $own = dispatch('GET', $path, true, ['scope'=>'my'])[1];
        routeCheck((int)$own[0]['kon'] === 3 && (int)$own[0]['count'] === 1, $kind . ' own grouping includes own waiting games');
        foreach (['', '/my'] as $mode) {
            $kon = $mode ? 3 : 5;
            list($status, $games) = dispatch('GET', 'v1/' . $kind . $mode, true, ['envelope'=>1,'filter'=>['kon'=>$kon]]);
            routeCheck($status === 200 && count($games['items']) === ($mode ? 1 : 2)
                && count(array_filter($games['items'], static function ($game) use ($kon) { return (float)$game['kon'] !== (float)$kon; })) === 0,
                $kind . ' stake filter applies to the actual ' . ($mode ? 'own' : 'available') . ' list');
        }
        routeCheck(dispatch('GET', $path, true, ['scope'=>['my']])[0] === 400
            && dispatch('GET', $path, true, ['scope'=>'unknown'])[0] === 400, $kind . ' malformed grouping scope is rejected');
        routeCheck(dispatch('GET', 'v1/' . $kind, true, ['filter'=>['kon'=>['invalid']]])[0] >= 400,
            $kind . ' malformed stake cannot bypass the filter');
    }
    list($today) = \common\services\user\PresenceService::dayBounds();
    $db->createCommand()->update('user', ['last_login_at'=>$today], ['id'=>2])->execute();
    $db->createCommand()->delete('forum_comment_gift')->execute();
    $db->createCommand()->delete('history_balance', ['type'=>'forum_gift'])->execute();
    foreach ([[1,5,$today], [2,3,$today-1]] as $gift) {
        $db->createCommand()->insert('forum_comment_gift', ['comment_id'=>$gift[0],'user_id'=>1,'created_at'=>$gift[2],'amount'=>$gift[1]])->execute();
        $db->createCommand()->insert('history_balance', ['user_id'=>1,'credit_up'=>-$gift[1],'type'=>'forum_gift','comment'=>'Благодарность за сообщение №'.$gift[0],'created_at'=>$gift[2]])->execute();
        $db->createCommand()->insert('history_balance', ['user_id'=>2,'credit_up'=>$gift[1],'type'=>'forum_gift','comment'=>'Благодарность за сообщение №'.$gift[0],'created_at'=>$gift[2]])->execute();
    }
    Yii::$app->cache->flush();
    $stats = dispatch('GET', 'v1/stat')[1];
    routeCheck($stats['periods']['forum']['credits'] === ['total'=>8,'today'=>5,'yesterday'=>3], 'forum credit totals use actual gift amounts once, excluding the paired recipient history');
    routeCheck($stats['visitors_today'] === 2, 'statistics counts distinct visitors from both presence and logins');
    foreach (['users','games','forum','transfers','exchange'] as $chart) {
        $points = $stats['charts'][$chart];
        routeCheck(count($points) === 7 && $points[6]['date'] === gmdate('Y-m-d', $today+10800)
            && count(array_filter($points, static function ($point) { return !is_int($point['value']) || $point['value'] < 0; })) === 0,
            $chart . ' chart contains seven calendar days with measured counts');
    }
    $top = dispatch('GET', 'v1/stat/top', false, ['envelope'=>1,'per-page'=>10])[1]['items'];
    routeCheck(count($top) === 2 && isset($top[0]['person']['rating'], $top[0]['created_at'], $top[0]['last_login_at'])
        && !isset($top[0]['email'], $top[0]['password_hash'], $top[0]['person']['credit'], $top[0]['person']['balance']), 'ranking includes public profiles and dates without private account fields');
    $db->createCommand('ALTER TABLE forum_comment_gift RENAME TO forum_comment_gift_modern')->execute();
    $db->createCommand('CREATE TABLE forum_comment_gift (id INTEGER PRIMARY KEY, comment_id INTEGER, user_id INTEGER, created_at INTEGER)')->execute();
    $db->createCommand('INSERT INTO forum_comment_gift SELECT id, comment_id, user_id, created_at FROM forum_comment_gift_modern')->execute();
    $db->schema->refresh(); Yii::$app->cache->flush();
    routeCheck(dispatch('GET', 'v1/stat')[1]['periods']['forum']['credits'] === ['total'=>8,'today'=>5,'yesterday'=>3],
        'forum credit statistics preserve amounts on the legacy schema without amount');
    $db->createCommand()->delete('credit_transfer')->execute();
    $db->createCommand()->update('persone', ['credit'=>12], ['user_id'=>1])->execute();
    list($status, $created) = dispatch('POST', 'v1/transfer', true, [], ['amount'=>5,'count'=>10]);
    routeCheck($status === 201 && $created['created'] === 2 && $created['requested'] === 10
        && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 2.0,
        'REST batch create loads count and reports affordable transfers');
} finally { $batch->rollBack(); $db->schema->refresh(); Yii::$app->cache->flush(); }
