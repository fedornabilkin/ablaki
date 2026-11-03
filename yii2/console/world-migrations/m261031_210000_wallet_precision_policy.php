<?php
use common\modules\world\support\WorldMigration;

/** Record an operator's precision decision so a resumed rollout uses the same policy. */
class m261031_210000_wallet_precision_policy extends WorldMigration
{
    public function safeUp()
    {
        $table = $this->db->schema->getTableSchema('economy_wallet_rollout', true);
        if (!$table->getColumn('precision_policy')) {
            $this->addColumn('economy_wallet_rollout', 'precision_policy', $this->string(24)->notNull()->defaultValue('strict'));
        }
    }
}
