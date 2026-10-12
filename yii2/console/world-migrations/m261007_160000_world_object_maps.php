<?php
use common\modules\world\models\Node;
use common\modules\world\models\domain\PlacementProvisioner;
use common\modules\world\support\Locks;
use common\modules\world\support\WorldDomainMigration;

/** Initialize empty object maps without rewriting existing cell rights or geometry. */
class m261007_160000_world_object_maps extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            $locks = new Locks($this->db);
            $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $provisioner = new PlacementProvisioner($this->db);
            $known = (new \yii\db\Query())->select('parent_id')->from('world_map_cell');
            foreach (Node::find()->where(['status' => 'active'])->andWhere(['not in', 'id', $known])->each(100, $this->db) as $node) $provisioner->openObjectMap($node);
        });
    }
}
