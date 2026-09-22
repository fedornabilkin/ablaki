<?php
$batch = $db->beginTransaction();
try {
    $db->createCommand()->update('forum_theme', ['view' => null], ['id' => 1])->execute();
    routeCheck(dispatch('GET', 'v1/forum-theme/1/visit')[0] >= 400, 'visits cannot be written with GET');
    routeCheck(dispatch('POST', 'v1/forum-theme/1/visit')[1]['view'] === 1 && dispatch('POST', 'v1/forum-theme/1/visit')[1]['view'] === 2, 'guest page visits atomically initialize and increment the counter');
    dispatch('GET', 'v1/forum-theme/1');
    routeCheck((int)$db->createCommand('SELECT view FROM forum_theme WHERE id=1')->queryScalar() === 2 && dispatch('POST', 'v1/forum-theme/999999/visit')[0] === 404, 'data refresh does not inflate page views and missing topic stays missing');
    foreach (['', '   ', "\xc2\xa0\xe2\x80\x8b", []] as $title) routeCheck(dispatch('POST', 'v1/forum-theme', true, [], ['title' => $title])[0] === 422, 'empty or malformed topic title is rejected');
    $topic = dispatch('POST', 'v1/forum-theme', true, [], ['title' => '  Valid topic  ']);
    routeCheck($topic[0] === 201 && $topic[1]['title'] === 'Valid topic', 'valid topic title is trimmed');
    foreach ([[9101, 1, 590, 1], [9102, 1, 601, 1], [9103, 2, 0, 1], [9104, 1, 0, 0], [9105, 1, -60, 1]] as $row) {
        $db->createCommand()->insert('forum_comment', ['id' => $row[0], 'user_id' => $row[1], 'created_at' => time() - $row[2], 'active' => $row[3], 'theme_id' => 1, 'comment' => 'original'])->execute();
    }
    $created = $db->createCommand('SELECT created_at FROM forum_comment WHERE id=9101')->queryScalar();
    routeCheck(dispatch('PATCH', 'v1/forum-comment/9101', false, [], ['comment' => 'edit'])[0] === 401, 'editing requires authentication');
    $edit = dispatch('PATCH', 'v1/forum-comment/9101', true, [], ['comment' => 'edited', 'created_at' => time(), 'user_id' => 2, 'theme_id' => 999, 'active' => 0]);
    routeCheck($edit[0] === 200 && $edit[1]['comment'] === 'edited' && (int)$edit[1]['user_id'] === 1 && (int)$edit[1]['theme_id'] === 1 && (int)$edit[1]['active'] === 1 && (int)$edit[1]['created_at'] === (int)$created, 'edit changes only text and never extends original deadline');
    foreach ([9102 => 403, 9103 => 403, 9104 => 404, 9105 => 403] as $id => $status) routeCheck(dispatch('PATCH', 'v1/forum-comment/' . $id, true, [], ['comment' => 'edit'])[0] === $status, 'expired foreign hidden or future message cannot be edited: ' . $id);
    routeCheck(dispatch('PATCH', 'v1/forum-comment/9101', true, [], [])[0] === 422 && dispatch('PATCH', 'v1/forum-comment/9101', true, [], ['comment' => []])[0] === 422, 'edit requires explicit string text');
    $db->createCommand()->delete('forum_comment_gift')->execute();
    $db->createCommand()->delete('history_balance')->execute();
    $db->createCommand()->update('persone', ['credit' => 10], ['user_id' => [1, 2]])->execute();
    foreach ([0, 4, -1, 1.5, '2', []] as $amount) routeCheck(dispatch('POST', 'v1/forum-comment/1/gift', true, [], ['amount' => $amount])[0] === 422, 'gift rejects invalid amount');
    $gift = dispatch('POST', 'v1/forum-comment/1/gift', true, [], ['amount' => 3]);
    $repeat = dispatch('POST', 'v1/forum-comment/1/gift', true, [], ['amount' => 2]);
    routeCheck($gift[0] === 200 && $gift[1]['amount'] === 3 && $gift[1]['credit'] === 7.0 && $repeat[1]['alreadyGiven'] && $repeat[1]['amount'] === 3 && $repeat[1]['credit'] === 7.0, 'three-credit gift and different-amount retry debit only once');
    routeCheck((float)$db->createCommand('SELECT credit FROM persone WHERE user_id=2')->queryScalar() === 13.0 && (int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 2 && (float)$db->createCommand('SELECT SUM(credit_up) FROM history_balance')->queryScalar() === 0.0, 'gift preserves total credits with exact paired histories');
    $gifts = dispatch('GET', 'v1/forum-comment/1/gifts', false, ['envelope' => '1'])[1];
    routeCheck((int)$gifts['items'][0]['amount'] === 3, 'donor list exposes actual gift amount');
    $db->createCommand()->update('forum_comment', ['created_at' => 0], ['id' => 1])->execute();
    routeCheck(dispatch('GET', 'v1/forum-theme/1', true, ['expand' => 'first_comment'])[1]['first_comment']['gifted_by_me'] === true
        && dispatch('GET', 'v1/forum-theme/1', false, ['expand' => 'first_comment'])[1]['first_comment']['gifted_by_me'] === false, 'starter gift state survives reload and remains viewer specific');
    routeCheck(dispatch('POST', 'v1/forum-comment/9103/gift', true, [], ['amount' => 2])[1]['credit'] === 5.0, 'two-credit gift debits the chosen amount');
    $person = \api\modules\v1\models\Person::findOne(['user_id' => 1]);
    routeCheck($person->toArray()['forum_credits_sent'] === 5, 'profile sums credits rather than counting gifts');
    $db->createCommand()->update('persone', ['credit' => 1], ['user_id' => 1])->execute();
    $db->createCommand()->insert('forum_comment', ['id' => 9106, 'user_id' => 2, 'active' => 1, 'comment' => 'poor', 'theme_id' => 1, 'created_at' => time()])->execute();
    routeCheck(dispatch('POST', 'v1/forum-comment/9106/gift', true, [], ['amount' => 2])[0] === 422 && (float)$db->createCommand('SELECT credit FROM persone WHERE user_id=1')->queryScalar() === 1.0, 'insufficient selected amount never partially debits');
    $db->createCommand()->delete('history_balance')->execute();
    $db->createCommand()->delete('history_rating')->execute();
    routeCheck(dispatch('GET', 'v1/bonus/available')[0] === 401, 'daily availability is private');
    $available = dispatch('GET', 'v1/bonus/available', true)[1];
    routeCheck(array_column($available['items'], 'id') === ['bonus', 'rating'] && $available['refresh_at'] > time(), 'daily availability lists unclaimed rewards and next day boundary');
    $rewardDay = \common\services\user\PresenceService::dayBounds()[0];
    $db->createCommand()->insert('history_balance', ['user_id' => 1, 'type' => 'everyday', 'created_at' => $rewardDay - 1])->execute();
    routeCheck(count(dispatch('GET', 'v1/bonus/available', true)[1]['items']) === 2, 'yesterday reward does not hide today reward');
    $db->createCommand()->insert('history_balance', ['user_id' => 1, 'type' => 'everyday', 'created_at' => $rewardDay])->execute();
    routeCheck(array_column(dispatch('GET', 'v1/bonus/available', true)[1]['items'], 'id') === ['rating'], 'claimed credit leaves rating available');
    $db->createCommand()->insert('history_rating', ['user_id' => 1, 'type' => 'everyday', 'created_at' => time()])->execute();
    routeCheck(dispatch('GET', 'v1/bonus/available', true)[1]['items'] === [], 'both claimed rewards disappear');
    $db->createCommand('ALTER TABLE forum_comment_gift RENAME TO forum_comment_gift_new')->execute();
    $db->createCommand('CREATE TABLE forum_comment_gift (id INTEGER PRIMARY KEY, comment_id INTEGER, user_id INTEGER, recipient_id INTEGER, created_at INTEGER, UNIQUE(comment_id,user_id))')->execute();
    $db->schema->refresh();
    routeCheck(\api\modules\v1\models\Person::findOne(['user_id' => 1])->toArray()['forum_credits_sent'] === 0, 'profile remains available before the amount migration');
    $db->createCommand()->update('persone', ['credit' => 10], ['user_id' => 1])->execute();
    $legacy = dispatch('POST', 'v1/forum-comment/9106/gift', true, [], ['amount' => 3]);
    routeCheck($legacy[0] === 200 && $legacy[1]['amount'] === 3 && $legacy[1]['credit'] === 7.0, 'legacy schema supports three-credit gifts using paired history');
    routeCheck(dispatch('POST', 'v1/forum-comment/9106/gift', true, [], ['amount' => 2])[1]['amount'] === 3, 'legacy different-amount retry returns original amount');
    routeCheck((int)dispatch('GET', 'v1/forum-comment/9106/gifts', false, ['envelope' => '1'])[1]['items'][0]['amount'] === 3, 'legacy donor list returns the amount from its exact debit');
    routeCheck(\common\modules\forum\services\CommentGiftSchema::sent($db, 1) === 3, 'legacy profile totals sum actual gifted credits');
    dispatch('POST', 'v1/forum-comment/9103/gift', true, [], ['amount' => 2]);
    routeCheck(\common\modules\forum\services\CommentGiftSchema::sent($db, 1) === 5, 'several legacy gifts are matched to their own debit histories');
    routeCheck(dispatch('GET', 'v1/forum-theme/1', false, ['expand' => 'first_comment'])[0] === 200, 'public profiles and themes work before amount migration');
    $db->createCommand('DROP TABLE forum_comment_gift')->execute();
    $db->createCommand('ALTER TABLE forum_comment_gift_new RENAME TO forum_comment_gift')->execute();
    $db->schema->refresh();
} finally { $batch->rollBack(); }
