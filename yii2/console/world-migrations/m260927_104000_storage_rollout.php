<?php
use common\modules\world\support\WorldMigration;

class m260927_104000_storage_rollout extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_storage_rollout', [
            'id' => $this->integer()->notNull(), 'phase' => $this->string(20)->notNull(),
            'catalog_hash' => $this->string(64), 'started_at' => $this->integer(), 'activated_at' => $this->integer(),
            'PRIMARY KEY ([[id]])',
        ]);
        $this->table('world_storage_baseline', [
            'user_id' => $this->reference('user') . ' NOT NULL',
            'before_json' => $this->db->driverName === 'mysql' ? 'LONGTEXT NOT NULL' : $this->text()->notNull(),
            'before_hash' => $this->string(64)->notNull(), 'after_hash' => $this->string(64)->notNull(),
            'migrated_at' => $this->integer()->notNull(), 'verified_at' => $this->integer(), 'PRIMARY KEY ([[user_id]])',
        ]);
        $this->foreign('fk_world_storage_baseline_user', 'world_storage_baseline', 'user_id', 'user');
        if (!(new \yii\db\Query())->from('world_storage_rollout')->where(['id' => 1])->exists($this->db)) $this->insert('world_storage_rollout', ['id' => 1, 'phase' => 'idle']);
    }
}
