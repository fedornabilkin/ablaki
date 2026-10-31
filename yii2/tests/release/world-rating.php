<?php
/** Full operation fixtures plus rating invariants. Dedicated CI databases only. */
require __DIR__ . '/world-terminal-nodes.php';

use common\modules\world\modules\progression\domain\WorldRatingPolicy;
use common\modules\world\modules\progression\models\domain\WorldRatingAwards;
use common\modules\world\models\domain\WorldMapCells;
use yii\db\Query;

$first = WorldRatingPolicy::award(0, 0, 0, [1]);
economyCheck($first['delta'] === 0.5, 'zero-rating account has a finite positive first reward');
foreach ([0.98, 0.99, 1, 10, 100] as $rating) {
    $current = WorldRatingPolicy::award($rating, 0, 0, [1]);
    $following = WorldRatingPolicy::award($rating + 0.01, 0, 0, [1]);
    economyCheck($following['delta'] <= $current['delta'], 'rating formula has no reward jump near the games threshold');
}
$weights = array_fill(0, 30, 0.1);
$bulk = WorldRatingPolicy::award(2, 2, 0, $weights);
$single = ['rating' => 2.0, 'peak' => 2.0, 'completed' => 0];
foreach ($weights as $weight) $single = WorldRatingPolicy::award($single['rating'], $single['peak'], $single['completed'], [$weight]);
economyCheck($bulk['rating'] === $single['rating'] && $bulk['completed'] === 30, 'one bulk craft and thirty individual batches earn exactly the same rating');
economyCheck(WorldRatingPolicy::award(0, 10, 100, [1])['delta'] < WorldRatingPolicy::award(10, 10, 0, [1])['delta'], 'rating loss and repeated actions cannot restore the beginner reward');
economyCheck(WorldRatingPolicy::award(1000000, 1000000, 1000000, [0.1])['delta'] === 0.0, 'rounding does not mint a minimum reward forever');
economyCheck(WorldRatingPolicy::award(2.1234567, 0, 0, [0])['rating'] === 2.1234567, 'zero weight cannot change an existing game rating through rounding');

foreach ([[0.1, 0.05], [0.0, 0.0]] as [$weight, $expectedRating]) {
    $tx = $db->beginTransaction();
    try {
        $user = $freshUser;
        $db->createCommand()->update('persone', ['rating' => null], ['user_id' => $user])->execute();
        $db->createCommand()->delete('world_rating_progress', ['user_id' => $user, 'action' => 'craft'])->execute();
        $wallet = (new Query())->from('persone')->where(['user_id' => $user])->one($db);
        $historyCount = (int)(new Query())->from('history_rating')->where(['user_id' => $user])->count('*', $db);
        $source = 'test:null-rating:' . ($weight > 0 ? 'gain' : 'zero');
        $awards = new WorldRatingAwards($db);
        $awards->award($user, 'craft', $source, bin2hex(random_bytes(16)), [$weight], 'First rating');
        $receipt = (new Query())->from('world_rating_award')->where(['user_id' => $user, 'source_key' => $source])->one($db);
        $after = (new Query())->from('persone')->where(['user_id' => $user])->one($db);
        $progress = (new Query())->from('world_rating_progress')->where(['user_id' => $user, 'action' => 'craft'])->one($db);
        economyCheck($receipt['rating_before'] !== null && (float)$receipt['rating_before'] === 0.0
            && (float)$receipt['rating_after'] === $expectedRating && (float)$receipt['rating_delta'] === $expectedRating
            && (float)$after['rating'] === $expectedRating && (int)$progress['completed_units'] === 1,
            'NULL legacy rating is recorded as zero for a ' . ($weight > 0 ? 'positive' : 'zero') . ' reward');
        economyCheck($after['credit'] === $wallet['credit'] && $after['balance'] === $wallet['balance']
            && (int)(new Query())->from('history_rating')->where(['user_id' => $user])->count('*', $db) === $historyCount + ($weight > 0 ? 1 : 0),
            'first rating preserves currency and records only an actual gain in history');
        $awards->award($user, 'craft', $source, bin2hex(random_bytes(16)), [$weight], 'Replay');
        economyCheck((new Query())->from('persone')->where(['user_id' => $user])->one($db) === $after
            && (new Query())->from('world_rating_progress')->where(['user_id' => $user, 'action' => 'craft'])->one($db) === $progress
            && (int)(new Query())->from('world_rating_award')->where(['user_id' => $user, 'source_key' => $source])->count('*', $db) === 1
            && (int)(new Query())->from('history_rating')->where(['user_id' => $user])->count('*', $db) === $historyCount + ($weight > 0 ? 1 : 0),
            'replaying the first rating source cannot duplicate its reward, progress or history');
    } finally { $tx->rollBack(); }
}

$pricing = ['base_price' => '2.0000', 'outer_ring_multiplier' => 3, 'bulk_minimum' => 3, 'discount_bps' => 500];
$price = WorldMapCells::purchasePricing($pricing, [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0], ['x' => -2, 'y' => -2]]);
economyCheck($price['gross_total'] === '26.0000' && $price['total'] === '24.7000', 'ring prices and package discount agree for positive and negative coordinates');
$tx = $db->beginTransaction();
try {
    $user = $freshUser;
    $map = new WorldMapCells($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world')), new \common\modules\world\models\domain\WorldAccessPolicy($user));
    $point = null;
    foreach ([[-1,-1], [0,-1], [1,-1], [-1,0], [1,0], [-1,1], [0,1], [1,1]] as [$x, $y]) {
        try { (new \common\modules\world\models\domain\WorldTree($db))->assertFreePosition($siteId, $x, $y); }
        catch (\common\modules\world\support\GameError $occupied) { continue; }
        $point = ['x' => $x, 'y' => $y]; break;
    }
    if (!$point) throw new RuntimeException('Fixture needs a free cell.');
    $db->createCommand()->delete('world_map_cell', ['parent_id' => $siteId] + $point)->execute();
    $input = $map->input($siteId, ['cells' => [$point], 'top_up' => true]);
    foreach (['explore', 'buy'] as $action) {
        $quote = $map->preview($user, $input, $action);
        $key = bin2hex(random_bytes(16));
        if ($action === 'buy' && $db->driverName === 'sqlite') {
            $wallet = (new Query())->from('persone')->where(['user_id' => $user])->one($db);
            $accounts = (new Query())->from('economy_account')->orderBy('id')->all($db);
            $db->pdo->exec("CREATE TRIGGER rating_purchase_failure BEFORE INSERT ON history_rating BEGIN SELECT RAISE(ABORT, 'rating purchase failure'); END");
            try {
                $map->execute($user, $key, $input, $quote['quote_id'], (array)$quote['expected_revisions'], $action);
                throw new RuntimeException('Rating failure must cancel the purchase.');
            } catch (\yii\db\Exception $expectedFailure) {}
            $db->pdo->exec('DROP TRIGGER rating_purchase_failure');
            economyCheck((new Query())->from('persone')->where(['user_id' => $user])->one($db) === $wallet
                && (new Query())->from('economy_account')->orderBy('id')->all($db) === $accounts
                && (new Query())->select('state')->from('world_map_cell')->where(['parent_id' => $siteId] + $point)->scalar($db) === 'discovered',
                'failed rating history rolls back a real cell purchase and both payment accounts');
        }
        $result = $map->execute($user, $key, $input, $quote['quote_id'], (array)$quote['expected_revisions'], $action);
        $rating = (new Query())->select('rating')->from('persone')->where(['user_id' => $user])->scalar($db);
        $again = $map->execute($user, $key, $input, $quote['quote_id'], (array)$quote['expected_revisions'], $action);
        economyCheck($again === $result && (new Query())->select('rating')->from('persone')->where(['user_id' => $user])->scalar($db) === $rating, 'map ' . $action . ' command replay preserves rating');
    }
    foreach (['explore', 'purchase', 'construction', 'craft'] as $action) {
        economyCheck((new Query())->from('world_rating_award')->where(['action' => $action])->exists($db), 'real successful ' . $action . ' operations reach the rating ledger');
    }
    $awards = new WorldRatingAwards($db);
    $operation = bin2hex(random_bytes(16));
    $before = (new Query())->from('persone')->where(['user_id' => $user])->one($db);
    $count = (int)(new Query())->from('history_rating')->where(['user_id' => $user])->count('*', $db);
    $awards->award($user, 'craft', 'test:rating-replay', $operation, [0.5, 0.5], 'Проверка двух партий');
    $after = (new Query())->from('persone')->where(['user_id' => $user])->one($db);
    $awards->award($user, 'craft', 'test:rating-replay', bin2hex(random_bytes(16)), [0.5, 0.5], 'Повтор');
    economyCheck((new Query())->from('persone')->where(['user_id' => $user])->one($db) === $after
        && (int)(new Query())->from('history_rating')->where(['user_id' => $user])->count('*', $db) === $count + 1,
        'replaying the same source even under a different operation cannot award twice');
    economyCheck($after['credit'] === $before['credit'] && $after['balance'] === $before['balance'] && $after['rating'] > $before['rating'], 'rating changes no currency');
    if ($db->driverName === 'sqlite') {
        $db->pdo->exec("CREATE TRIGGER rating_history_failure BEFORE INSERT ON history_rating BEGIN SELECT RAISE(ABORT, 'history failure'); END");
        $progress = (new Query())->from('world_rating_progress')->where(['user_id' => $user])->orderBy('action')->all($db);
        try {
            $db->transaction(static function () use ($awards, $user, $operation) {
                $awards->award($user, 'craft', 'test:rating-rollback', $operation, [0.5], 'Откат');
            });
            throw new RuntimeException('History failure must reject the operation.');
        } catch (\yii\db\Exception $expected) {}
        economyCheck((new Query())->from('persone')->where(['user_id' => $user])->one($db) === $after
            && !(new Query())->from('world_rating_award')->where(['source_key' => 'test:rating-rollback'])->exists($db)
            && (new Query())->from('world_rating_progress')->where(['user_id' => $user])->orderBy('action')->all($db) === $progress, 'history failure rolls back rating, counters and receipt');
    }
} finally { $tx->rollBack(); }
$tx = $db->beginTransaction();
try {
    // Recreate a previously published revision; do not mutate the existing immutable row.
    $fixtureProfile = static function (string $base, int $outer) use ($db): int {
        $currentId = (new Query())->select('profile_id')->from('world_economy_current')->where(['id' => 1])->scalar($db);
        $row = (new Query())->from('world_economy_profile')->where(['id' => $currentId])->one($db);
        unset($row['id']);
        $config = json_decode($row['config_json'], true);
        $config['map']['base_price_cr'] = $base; $config['map']['outer_ring_multiplier'] = $outer;
        $row['config_json'] = \common\modules\world\support\CanonicalJson::encode($config);
        $row['digest'] = hash('sha256', $row['config_json']);
        $row['version'] = 1 + (int)(new Query())->from('world_economy_profile')->max('version', $db);
        $db->createCommand()->insert('world_economy_profile', $row)->execute();
        $id = (int)$db->getLastInsertID();
        $db->createCommand()->update('world_economy_current', ['profile_id' => $id], ['id' => 1])->execute();
        return $id;
    };
    $fixtureProfile('0.5000', 10);
    $before = \common\modules\world\models\domain\EconomyRuntime::current($db);
    $pointerRevision = (int)(new Query())->select('revision')->from('world_economy_current')->where(['id' => 1])->scalar($db);
    $owned = (new Query())->from('world_map_cell')->where(['state' => 'open'])->orderBy('parent_id,x,y')->all($db);
    $migration = new \m261031_170000_world_map_prices(['db' => $db]);
    $migration->safeUp();
    $after = \common\modules\world\models\domain\EconomyRuntime::current($db);
    $expected = $before['raw_profile'];
    $expected['map']['base_price_cr'] = '2.0000'; $expected['map']['outer_ring_multiplier'] = 3; $expected['updated_on'] = '2026-10-31';
    economyCheck($after['raw_profile'] === $expected, 'map publication preserves every other accepted profile setting');
    economyCheck($after['id'] !== $before['id'] && $after['version'] === $before['version'] + 1
        && (int)(new Query())->select('revision')->from('world_economy_current')->where(['id' => 1])->scalar($db) === $pointerRevision + 1,
        'old standard prices publish a new immutable version and advance the pointer revision');
    $migration->safeUp();
    economyCheck(\common\modules\world\models\domain\EconomyRuntime::current($db)['id'] === $after['id']
        && (new Query())->from('world_map_cell')->where(['state' => 'open'])->orderBy('parent_id,x,y')->all($db) === $owned, 'price migration is repeatable and never reprices purchased cells');
    $customId = $fixtureProfile('3.0000', 4);
    $migration->safeUp();
    economyCheck(\common\modules\world\models\domain\EconomyRuntime::current($db)['id'] === $customId,
        'custom administrative map prices remain untouched');
} finally { $tx->rollBack(); }
echo "World map and rating checks passed.\n";
