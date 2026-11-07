<?php
use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\Locks;
use common\modules\world\support\WorldMigration;
use yii\db\Expression;
use yii\db\Query;

/** New immutable revision; preserve all live custom prices and accepted supply receipts. */
class m261106_181000_world_daily_carrot extends WorldMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            $locks = new Locks($this->db);
            $locks->row('craft_meta', ['id' => 1]);
            $locks->row('world_registry', ['id' => 1]);
            $id = (new Query())->select('profile_id')->from('world_economy_current')->where(['id' => 1])->scalar($this->db);
            $old = $id ? (new Query())->from('world_economy_profile')->where(['id' => $id])->one($this->db) : null;
            if (!$old) return;
            $profile = json_decode($old['config_json'], true, 64, JSON_THROW_ON_ERROR);
            if (($profile['daily']['fixed_items']['world-carrot'] ?? null) === 1 && !isset($profile['daily']['items']['world-carrot'])) return;
            $profile['daily']['fixed_items']['world-carrot'] = 1;
            unset($profile['daily']['items']['world-carrot'], $profile['daily']['suppressed_by']['world-carrot']);
            $profile['updated_on'] = '2026-11-06';
            EconomyProfile::validate($profile);
            $next = $old;
            unset($next['id']);
            $next['version'] = 1 + (int)(new Query())->from('world_economy_profile')->max('version', $this->db);
            $next['config_json'] = CanonicalJson::encode($profile);
            $next['digest'] = hash('sha256', $next['config_json']);
            $next['catalog_before_json'] = CanonicalJson::encode(['scope' => 'daily_carrot_only', 'previous_profile_id' => (int)$id]);
            $next['reason'] = 'Одна морковь ежедневно: еда или две семечки для восстановления огорода.';
            $next['created_at'] = time();
            if ($this->db->createCommand()->insert('world_economy_profile', $next)->execute() !== 1) throw new \RuntimeException('Daily profile write failed.');
            $nextId = (int)$this->db->getLastInsertID();
            if ($this->db->createCommand()->update('world_economy_current', ['profile_id' => $nextId, 'revision' => new Expression('[[revision]]+1')], ['id' => 1, 'profile_id' => $id])->execute() !== 1)
                throw new \RuntimeException('Daily profile publication failed.');
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
        });
    }
}
