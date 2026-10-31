<?php

use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\Locks;
use common\modules\world\support\WorldMigration;
use yii\db\Expression;
use yii\db\Query;

/** Publish map prices only; retain custom prices, every owned cell and all other rules. */
class m261031_170000_world_map_prices extends WorldMigration
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
            if (($profile['map']['base_price_cr'] ?? null) !== '0.5000' || ($profile['map']['outer_ring_multiplier'] ?? null) !== 10) return;
            $profile['map']['base_price_cr'] = '2.0000';
            $profile['map']['outer_ring_multiplier'] = 3;
            $profile['updated_on'] = '2026-10-31';
            EconomyProfile::validate($profile);
            $next = $old;
            unset($next['id']);
            $next['version'] = 1 + (int)(new Query())->from('world_economy_profile')->max('version', $this->db);
            $next['config_json'] = CanonicalJson::encode($profile);
            $next['digest'] = hash('sha256', $next['config_json']);
            $next['catalog_before_json'] = CanonicalJson::encode(['scope' => 'map_prices_only', 'previous_profile_id' => (int)$id]);
            $next['reason'] = 'Баланс ячеек: первое кольцо 2 Cr, второе 12 Cr; остальные правила сохранены.';
            $next['created_at'] = time();
            $this->db->createCommand()->insert('world_economy_profile', $next)->execute();
            $nextId = (int)$this->db->getLastInsertID();
            if ($this->db->createCommand()->update('world_economy_current', ['profile_id' => $nextId, 'revision' => new Expression('[[revision]]+1')], ['id' => 1, 'profile_id' => $id])->execute() !== 1) {
                throw new \RuntimeException('Map price publication failed.');
            }
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
        });
    }
}
