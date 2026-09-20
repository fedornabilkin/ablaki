<?php
$batch = $db->beginTransaction();
try {
    $db->createCommand()->delete('credit_transfer')->execute();
    foreach ([[1, 1, 2], [2, 1, 0], [3, 2, 1], [4, 2, 0]] as $transfer) {
        $db->createCommand()->insert('credit_transfer', ['id' => $transfer[0], 'user_id' => $transfer[1], 'user_buyer' => $transfer[2], 'amount' => 10, 'password' => 'private-code', 'created_at' => 100, 'updated_at' => 200])->execute();
    }
    $received = dispatch('GET', 'v1/transfer/history', true, ['envelope' => 1, 'sort' => 'id']);
    routeCheck($received[0] === 200 && array_map('intval', array_column($received[1]['items'], 'id')) === [1, 3], 'received transfers include only own sent and received transfers');
    $transfer = $received[1]['items'][0];
    routeCheck($transfer['received_at'] === 200 && $transfer['recipient']['username'] === 'Author' && isset($transfer['recipient']['person']['rating']) && !array_key_exists('email', $transfer['recipient']), 'transfer receipt returns the actual completion date and safe recipient profile');
    routeCheck($received[1]['items'][1]['password'] === null, 'recipient cannot read the sender transfer secret');
    $pending = dispatch('GET', 'v1/transfer', true, ['envelope' => 1])[1]['items'];
    routeCheck(count($pending) === 1 && (int)$pending[0]['id'] === 2 && $pending[0]['received_at'] === null && $pending[0]['recipient'] === null && $pending[0]['password'] === 'private-code', 'pending transfer preserves its sender-only collection hash and has no invented receipt');
    $db->createCommand()->delete('forum_comment')->execute();
    $db->createCommand()->delete('forum_theme')->execute();
    foreach ([1, 2, 3, 4, 5, 6, 7] as $id) {
        $db->createCommand()->insert('forum_theme', ['id' => $id, 'user_id' => 1, 'title' => 'Topic ' . $id, 'created_at' => $id, 'view' => 0])->execute();
        if ($id !== 7) $db->createCommand()->insert('forum_comment', ['theme_id' => $id, 'user_id' => 1, 'active' => 1, 'created_at' => 100 - $id, 'comment' => 'Visible'])->execute();
    }
    $db->createCommand()->insert('forum_comment', ['theme_id' => 6, 'user_id' => 1, 'active' => 0, 'created_at' => 999, 'comment' => 'Hidden'])->execute();
    $latest = dispatch('GET', 'v1/forum-theme', false, ['envelope' => '1', 'per-page' => '5', 'sort' => '-last_comment_created_at']);
    routeCheck($latest[0] === 200 && array_map('intval', array_column($latest[1]['items'], 'id')) === [1, 2, 3, 4, 5], 'homepage selects five topics by newest visible reply, not topic id or hidden reply');
    foreach (['orel', 'saper', 'duel', 'five'] as $kind) {
        $table = 'game_' . $kind;
        $db->createCommand()->delete($table)->execute();
        for ($id = 1; $id <= 5; $id++) {
            $row = ['id' => $id, 'user_id' => 1, 'user_gamer' => 2, 'kon' => 5, 'created_at' => 1, $kind === 'saper' ? 'time_over_at' : 'updated_at' => 100 - $id];
            if ($kind === 'orel') $row += ['type' => 1, 'hod' => 1];
            elseif ($kind === 'saper') $row['etap'] = \common\modules\games\models\GameSaper::GAME_SAPER_ETAP_WIN;
            elseif ($kind === 'duel') $row += ['u1' => 1, 'b1' => 1, 'u2' => 2, 'b2' => 1];
            else $row['status'] = str_pad('gamer', 50);
            $db->createCommand()->insert($table, $row)->execute();
        }
        $db->createCommand()->update($table, ['user_gamer' => 0], ['id' => 1])->execute();
        $result = dispatch('GET', 'v1/stat/recent-games', false, ['kind' => $kind]);
        routeCheck($result[0] === 200 && array_column($result[1], 'id') === [2, 3, 4], $kind . ' public preview contains only the latest three completed games');
        $row = $result[1][0];
        routeCheck($row['winner'] === 'player' && $row['creator']['username'] === 'Donor' && $row['player']['username'] === 'Author'
            && isset($row['creator']['person']['rating']) && !isset($row['creator']['email'], $row['u1'], $row['hod']), $kind . ' preview exposes both public players and winner without private identity or moves');
        $db->createCommand()->delete($table)->execute();
        routeCheck(dispatch('GET', 'v1/stat/recent-games', false, ['kind' => $kind])[1] === [], $kind . ' empty preview is a real empty list');
    }
    routeCheck(dispatch('GET', 'v1/stat/recent-games', false, ['kind' => 'unknown'])[0] === 400, 'unknown preview kind is rejected');
} finally { $batch->rollBack(); }
