<?php
use common\modules\world\support\WorldDomainMigration;

/** Keep completed construction rows; one unfinished construction/repair per object. */
class m261008_210000_world_object_repair extends WorldDomainMigration
{
    private function column(string $table, string $name, $type): void
    {
        if (!isset($this->db->schema->getTableSchema($table, true)->columns[$name])) $this->addColumn($table, $name, $type);
    }
    public function safeUp()
    {
        $this->column('world_build', 'job_kind', $this->string(16)->notNull()->defaultValue('construction'));
        $this->column('world_build', 'initial_condition', $this->integer()->notNull()->defaultValue(0));
        $this->column('world_build', 'active_node_id', $this->reference('world_node'));
        $this->index('ix_world_build_node_id', 'world_build', ['node_id']);
        foreach ($this->db->schema->getTableIndexes('world_build', true) as $index)
            if ($index->name === 'ux_world_build_node_id') $this->dropIndex($index->name, 'world_build');
        $this->db->transaction(function () {
            (new \common\modules\world\support\Locks($this->db))->row('craft_meta', ['id'=>1]);
            $this->db->createCommand()->update('world_build', ['active_node_id'=>new \yii\db\Expression('[[node_id]]')], ['status'=>'building','active_node_id'=>null])->execute();
        });
        $this->index('ux_world_build_active_node_id', 'world_build', ['active_node_id'], true);
        $this->foreign('fk_world_build_active_node_id', 'world_build', 'active_node_id', 'world_node');
    }
}
