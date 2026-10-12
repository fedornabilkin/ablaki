<?php
use common\modules\world\models\Node;
use common\modules\world\models\domain\PlacementProvisioner;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\support\Locks;
use common\modules\world\support\WorldDomainMigration;

/** Add common facilities to existing nodes; do not rewrite maps, assets or balances. */
class m261009_170000_world_common_foundation extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'world.common.foundation', 'created_at' => time()])->execute();
            $placement = new PlacementProvisioner($this->db); $economy = new EconomyHierarchy($this->db);
            foreach (Node::find()->where(['status' => 'active'])->orderBy(['depth' => SORT_ASC, 'id' => SORT_ASC])->all() as $node) {
                $placement->openObjectMap($node, $operation);
                $economy->provision((int)$node->id, $operation);
                if ($node->owner_user_id) $placement->interior($node);
            }
        });
    }
}
