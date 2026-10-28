<?php
use common\modules\world\models\Node;
use common\modules\world\models\domain\PlacementProvisioner;
use common\modules\world\support\Locks;
use common\modules\world\support\WorldDomainMigration;

/** Add interior cells/slots to existing objects without changing inventory or paid territory. */
class m261006_190000_world_interior_placement extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $provisioner = new PlacementProvisioner($this->db);
            foreach (Node::find()->where(['status' => 'active'])->andWhere(['or', ['node_type' => ['BUILDING', 'ROOM', 'PLACE', 'CHEST', 'BED']], ['plot_kind' => 'garden']])->each(100, $this->db) as $node) {
                $provisioner->openInteriorMap($node);
                if ($node->owner_user_id && in_array($node->node_type, ['ROOM', 'PLACE'], true)) $provisioner->interior($node);
            }
        });
    }
    public function safeDown() { return false; }
}
