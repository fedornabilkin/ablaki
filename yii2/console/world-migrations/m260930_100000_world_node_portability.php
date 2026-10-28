<?php
use common\modules\world\support\WorldDomainMigration;

/** Explicit portability flag for objects that may be relocated on their parent's map. */
class m260930_100000_world_node_portability extends WorldDomainMigration
{
    public function safeUp()
    {
        $node = $this->db->schema->getTableSchema('world_node', true);
        if (!isset($node->columns['portable'])) $this->addColumn('world_node', 'portable', $this->smallInteger()->notNull()->defaultValue(0));
    }
}
