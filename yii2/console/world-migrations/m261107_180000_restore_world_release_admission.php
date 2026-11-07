<?php
use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\Locks;
use common\modules\world\support\WorldMigration;
use yii\db\Expression;
use yii\db\Query;

/** Repair only the automatic release that replaced an already public admission policy. */
class m261107_180000_restore_world_release_admission extends WorldMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            $locks = new Locks($this->db);
            $locks->row('craft_meta', ['id' => 1]);
            $locks->row('world_registry', ['id' => 1]);
            $id = (new Query())->select('profile_id')->from('world_economy_current')->where(['id' => 1])->scalar($this->db);
            $current = $id ? (new Query())->from('world_economy_profile')->where(['id' => $id])->one($this->db) : null;
            if (!$current || $current['reason'] !== 'Production release: approved construction pacing, NPC market and rewards') return;
            $previous = (new Query())->from('world_economy_profile')->where(['<', 'version', $current['version']])
                ->orderBy(['version' => SORT_DESC])->one($this->db);
            if (!$previous) return;
            $profile = json_decode($current['config_json'], true, 64, JSON_THROW_ON_ERROR);
            $old = json_decode($previous['config_json'], true, 64, JSON_THROW_ON_ERROR);
            if (($profile['closed_test']['restricted'] ?? null) !== true || ($old['closed_test']['restricted'] ?? null) !== false) return;
            $profile['closed_test'] = $old['closed_test'];
            EconomyProfile::validate($profile);
            $next = $current;
            unset($next['id']);
            $next['version'] = 1 + (int)(new Query())->from('world_economy_profile')->max('version', $this->db);
            $next['config_json'] = CanonicalJson::encode($profile);
            $next['digest'] = hash('sha256', $next['config_json']);
            $next['catalog_before_json'] = CanonicalJson::encode(['scope' => 'restore_release_admission_only', 'previous_profile_id' => (int)$id]);
            $next['reason'] = 'Restore existing public admission after automatic economy release; preserve balance changes and gameplay data';
            $next['created_at'] = time();
            if ($this->db->createCommand()->insert('world_economy_profile', $next)->execute() !== 1) throw new \RuntimeException('Admission profile write failed.');
            $nextId = (int)$this->db->getLastInsertID();
            if ($this->db->createCommand()->update('world_economy_current', ['profile_id' => $nextId, 'revision' => new Expression('[[revision]]+1')], ['id' => 1, 'profile_id' => $id])->execute() !== 1)
                throw new \RuntimeException('Admission publication failed.');
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
        });
    }
}
