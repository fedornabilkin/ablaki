<?php

use common\modules\world\support\Locks;
use common\modules\world\support\WorldMigration;
use yii\db\Expression;
use yii\db\Query;

/** One mid-cycle watering with enough time to react; accepted crops retain their rules. */
class m261031_140000_world_starter_watering extends WorldMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            $crop = (new Query())->select('id')->from('world_crop')->where(['code' => 'starter-carrot'])->scalar($this->db);
            if (!$crop) return;
            $current = (new Query())->from('world_crop_revision')->where(['crop_id' => $crop, 'status' => 'published'])->one($this->db);
            // Do not overwrite timing chosen by an administrator or repeat the publication.
            if (!$current || (int)$current['grow_seconds'] !== 300 || (int)$current['water_interval_seconds'] !== 120 || (int)$current['water_window_seconds'] !== 60) return;
            $next = $current;
            unset($next['id']);
            $next['version'] = 1 + (int)(new Query())->from('world_crop_revision')->where(['crop_id' => $crop])->max('version', $this->db);
            $next['water_interval_seconds'] = 150;
            $next['water_window_seconds'] = 150;
            $next['published_at'] = time();
            $this->db->createCommand()->update('world_crop_revision', ['status' => 'superseded'], ['id' => $current['id']])->execute();
            $this->db->createCommand()->insert('world_crop_revision', $next)->execute();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
        });
    }
}
