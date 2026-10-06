<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Called by onboarding/backfill now, and paid construction later; never from GET. */
class PlacementProvisioner
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function campsite(int $user, int $node): int
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Placement provisioning requires a transaction.');
        $site = (new Query())->from('world_node')->where(['id' => $node, 'owner_user_id' => $user, 'node_type' => 'PLOT', 'status' => 'active'])->one($this->db);
        $plot = (new Query())->from('world_plot')->where(['node_id' => $node, 'plot_kind' => 'campsite'])->one($this->db);
        if (!$site || !$plot) throw new \LogicException('Expected the owned starter campsite.');
        $key = 'placement:node:' . $node;
        $storage = (new Query())->from('craft_storage')->where(['identity_key' => $key])->one($this->db);
        if (!$storage) {
            $this->db->createCommand()->insert('craft_storage', ['identity_key' => $key, 'kind' => 'placement', 'owner_user_id' => $user, 'node_id' => $node, 'capacity' => 4])->execute();
            $storage = ['id' => (int)$this->db->getLastInsertID()];
        }
        for ($position = 1; $position <= 4; $position++) {
            $where = ['storage_id' => $storage['id'], 'position' => $position];
            if (!(new Query())->from('world_slot')->where($where)->exists($this->db)) $this->db->createCommand()->insert('world_slot', $where + ['code' => 'outdoor-' . $position, 'slot_type' => 'equipment', 'size' => 1, 'exposure_class' => 'outdoor', 'compatibility_json' => '{}'])->execute();
        }
        return (int)$storage['id'];
    }
}
