<?php

use common\modules\world\support\WorldMigration;

/** No retroactive awards. Resumable additive DDL, including MySQL implicit commits. */
class m261031_160000_world_rating extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_rating_progress', [
            'user_id' => $this->reference('user'), 'action' => $this->string(20)->notNull(),
            'completed_units' => $this->bigInteger()->notNull()->defaultValue(0),
            'peak_rating' => $this->decimal(20, 5)->notNull()->defaultValue(0),
            'PRIMARY KEY ([[user_id]], [[action]])',
        ]);
        $this->table('world_rating_award', [
            'id' => $this->bigPrimaryKey(), 'user_id' => $this->reference('user'),
            'action' => $this->string(20)->notNull(), 'source_key' => $this->string(120)->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'policy_version' => $this->integer()->notNull(),
            'units' => $this->integer()->notNull(), 'weights_json' => $this->text()->notNull(), 'rating_before' => $this->decimal(20, 5)->notNull(),
            'rating_after' => $this->decimal(20, 5)->notNull(), 'rating_delta' => $this->decimal(20, 5)->notNull(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_world_rating_source', 'world_rating_award', ['user_id', 'source_key'], true);
        $this->index('ix_world_rating_operation', 'world_rating_award', ['operation_id']);
        $this->foreign('fk_world_rating_progress_user', 'world_rating_progress', 'user_id', 'user');
        $this->foreign('fk_world_rating_award_user', 'world_rating_award', 'user_id', 'user');
    }
}
