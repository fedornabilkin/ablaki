<?php
use common\modules\world\support\WorldDomainMigration;

class m260928_002200_world_progression extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('requirement_set', ['code' => $this->string(80)->notNull()], [], [['code']]);
        $this->domain('requirement_revision', ['version' => $this->integer()->notNull(), 'rules_json' => $this->text()->notNull(), 'status' => $this->string(16)->notNull(), 'published_at' => $this->integer()],
            ['set_id' => ['requirement_set'], 'author_user_id' => ['user']], [['set_id', 'version']], [['status', 'id']]);
        $this->domain('profession', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull(), 'status' => $this->string(16)->notNull()->defaultValue('draft')], [], [['code']]);
        $this->domain('profession_revision', ['version' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'max_level' => $this->integer()->notNull(), 'config_json' => $this->text()->notNull(), 'published_at' => $this->integer()],
            ['profession_id' => ['profession'], 'author_user_id' => ['user']], [['profession_id', 'version']], [['profession_id', 'status', 'id']]);
        $this->domain('profession_level', ['level' => $this->integer()->notNull(), 'required_xp' => $this->bigInteger()->notNull(), 'limits_json' => $this->text()->notNull()],
            ['revision_id' => ['profession_revision'], 'requirement_revision_id' => ['requirement_revision', 'id', false]], [['revision_id', 'level']]);
        $this->domain('profession_current', [], ['profession_id' => ['profession'], 'revision_id' => ['profession_revision']], [['revision_id']], [], ['profession_id']);
        $this->domain('actor_profession', ['xp' => $this->bigInteger()->notNull()->defaultValue(0), 'level' => $this->integer()->notNull()->defaultValue(1), 'revision' => $this->integer()->notNull()->defaultValue(1), 'updated_at' => $this->integer()->notNull()],
            ['actor_id' => ['game_actor'], 'profession_id' => ['profession'], 'rules_revision_id' => ['profession_revision']], [], [], ['actor_id', 'profession_id']);
        $this->domain('progression_award', ['source_key' => $this->string(120)->notNull(), 'xp' => $this->integer()->notNull(), 'created_at' => $this->integer()->notNull()],
            ['actor_id' => ['game_actor'], 'profession_id' => ['profession'], 'rules_revision_id' => ['profession_revision'], 'operation_id' => ['game_operation'], 'source_event_id' => ['game_outbox', 'id', false]],
            [['actor_id', 'profession_id', 'source_key']], [['actor_id', 'id']]);
        $this->domain('progression_daily_limit', ['day_key' => $this->string(10)->notNull(), 'source_code' => $this->string(64)->notNull(), 'awarded_xp' => $this->integer()->notNull()->defaultValue(0)],
            ['actor_id' => ['game_actor'], 'profession_id' => ['profession']], [], [], ['actor_id', 'profession_id', 'day_key', 'source_code']);
        $this->domain('achievement', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull()], [], [['code']]);
        $this->domain('actor_achievement', ['earned_at' => $this->integer()->notNull()], ['actor_id' => ['game_actor'], 'achievement_id' => ['achievement'], 'operation_id' => ['game_operation'], 'source_event_id' => ['game_outbox', 'id', false]], [], [], ['actor_id', 'achievement_id']);
        $this->domain('actor_level_claim', ['level' => $this->integer()->notNull(), 'claimed_at' => $this->integer()->notNull()],
            ['actor_id' => ['game_actor'], 'profession_id' => ['profession'], 'rules_revision_id' => ['profession_revision'], 'operation_id' => ['game_operation']], [['actor_id', 'profession_id', 'level']], [['operation_id']]);
        // Only identifiers/names from the specification, never invented XP thresholds or prices.
        foreach (['merchant' => 'Торговец', 'builder' => 'Строитель', 'gatherer' => 'Добытчик', 'hunter' => 'Охотник', 'specialist' => 'Специалист'] as $code => $name) {
            if (!(new \yii\db\Query())->from('profession')->where(['code' => $code])->exists($this->db)) $this->insert('profession', ['code' => $code, 'name' => $name, 'status' => 'draft']);
        }
    }
}
