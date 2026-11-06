<?php
use common\modules\world\support\WorldDomainMigration;

class m261106_180000_world_telegram_reward extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_telegram_reward', [
            'user_id' => $this->integer()->notNull(), 'identity_hash' => $this->string(64)->notNull(),
            'pack_code' => $this->string(32)->notNull(), 'items_json' => $this->text()->notNull(),
            'operation_id' => $this->string(32)->notNull(), 'created_at' => $this->integer()->notNull(),
        ], ['user_id' => ['user'], 'operation_id' => ['game_operation']], [['user_id'], ['identity_hash'], ['operation_id']]);
    }
}
