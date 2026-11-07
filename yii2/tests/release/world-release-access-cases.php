<?php
// Runs only inside the disposable economy runtime fixture, also on MySQL/PostgreSQL CI.
require_once dirname(__DIR__, 2) . '/console/world-migrations/m261107_180000_restore_world_release_admission.php';
(static function () use ($db): void {
    $releaseReason = 'Production release: approved construction pacing, NPC market and rewards';
    foreach ([[false, $releaseReason, true], [true, $releaseReason, false], [false, 'Administrator deliberately restricted admission', false]] as [$wasRestricted, $reason, $repair]) {
        $transaction = $db->beginTransaction();
        try {
            $protected = [];
            foreach (['economy_account', 'craft_inventory', 'world_build'] as $table)
                $protected[$table] = (new \yii\db\Query())->from($table)->orderBy('id')->all($db);
            $row = (new \yii\db\Query())->from('world_economy_profile')->orderBy(['version' => SORT_DESC])->one($db);
            unset($row['id']);
            $profile = \common\modules\world\models\domain\EconomyProfile::load();
            $profile['closed_test']['restricted'] = $wasRestricted;
            $profile['closed_test']['feedback_path'] = '/forum/read/64';
            $insert = static function (array $data, string $why) use ($db, &$row): int {
                $row['version']++;
                $row['reason'] = $why;
                $row['config_json'] = \common\modules\world\support\CanonicalJson::encode($data);
                $row['digest'] = hash('sha256', $row['config_json']);
                $db->createCommand()->insert('world_economy_profile', $row)->execute();
                return (int)$db->getLastInsertID();
            };
            $insert($profile, 'Operator admission policy');
            $candidate = \common\modules\world\models\domain\EconomyProfile::load();
            $id = $insert($candidate, $reason);
            $db->createCommand()->update('world_economy_current', ['profile_id' => $id], ['id' => 1])->execute();
            $migration = new \m261107_180000_restore_world_release_admission(['db' => $db]);
            ob_start(); try { $migration->up(); } finally { ob_end_clean(); }
            $current = \common\modules\world\models\domain\EconomyRuntime::current($db);
            $expected = $candidate;
            if ($repair) $expected['closed_test'] = $profile['closed_test'];
            economyCheck(\common\modules\world\support\CanonicalJson::encode($current['raw_profile']) === \common\modules\world\support\CanonicalJson::encode($expected), 'admission repair changes only a regressed automatic release and preserves all economy rules');
            economyCheck(($current['id'] !== $id) === $repair, 'admission repair never republishes intentional restrictions');
            ob_start(); try { $migration->up(); } finally { ob_end_clean(); }
            economyCheck(\common\modules\world\models\domain\EconomyRuntime::current($db)['id'] === $current['id'], 'admission repair is idempotent');
            foreach ($protected as $table => $rows)
                economyCheck((new \yii\db\Query())->from($table)->orderBy('id')->all($db) === $rows, 'admission repair preserves ' . $table);
        } finally {
            $transaction->rollBack();
        }
    }
})();
