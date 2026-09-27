<?php
use common\services\game\WorldMigration;

class m260927_100000_world_foundation extends WorldMigration
{
    public function safeUp()
    {
        $user = $this->reference('user');
        $this->table('world_template', ['id' => $this->primaryKey(), 'code' => $this->string(80)->notNull(), 'kind' => $this->string(20)->notNull()]);
        $this->index('ux_world_template_code', 'world_template', ['code'], true);
        $this->table('world_template_revision', [
            'id' => $this->primaryKey(), 'template_id' => $this->integer()->notNull(), 'version' => $this->integer()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('draft'), 'config_json' => $this->text()->notNull(),
            'author_user_id' => $user, 'published_at' => $this->integer(),
        ]);
        $this->index('ux_world_template_version', 'world_template_revision', ['template_id', 'version'], true);
        $this->foreign('fk_world_template_revision', 'world_template_revision', 'template_id', 'world_template');
        $this->foreign('fk_world_template_author', 'world_template_revision', 'author_user_id', 'user');
        $this->table('world_node', [
            'id' => $this->primaryKey(), 'parent_id' => $this->integer(), 'root_id' => $this->integer(),
            'code' => $this->string(80)->notNull(), 'slug' => $this->string(80)->notNull(), 'node_type' => $this->string(20)->notNull(),
            'name' => $this->string(120)->notNull(), 'owner_user_id' => $user, 'visibility' => $this->string(16)->notNull()->defaultValue('public'),
            'status' => $this->string(20)->notNull()->defaultValue('active'), 'depth' => $this->integer()->notNull()->defaultValue(0),
            'position_x' => $this->integer()->notNull()->defaultValue(0), 'position_y' => $this->integer()->notNull()->defaultValue(0),
            'position' => $this->integer()->notNull()->defaultValue(0), 'revision' => $this->integer()->notNull()->defaultValue(1),
            'created_at' => $this->integer()->notNull(), 'updated_at' => $this->integer()->notNull(),
        ]);
        foreach ([['ux_world_node_code', ['code'], true], ['ux_world_node_slug', ['parent_id', 'slug'], true],
            ['idx_world_node_parent', ['parent_id', 'status', 'id'], false], ['idx_world_node_owner', ['owner_user_id', 'id'], false],
            ['idx_world_node_root', ['root_id', 'node_type', 'status', 'id'], false]] as $index) $this->index($index[0], 'world_node', $index[1], $index[2]);
        $this->foreign('fk_world_node_parent', 'world_node', 'parent_id', 'world_node');
        $this->foreign('fk_world_node_root', 'world_node', 'root_id', 'world_node');
        $this->foreign('fk_world_node_owner', 'world_node', 'owner_user_id', 'user');
        $this->table('world_registry', [
            'id' => $this->integer()->notNull(), 'active_world_id' => $this->integer(), 'content_revision' => $this->integer()->notNull()->defaultValue(1),
            'world_read' => $this->smallInteger()->notNull()->defaultValue(0), 'world_write' => $this->smallInteger()->notNull()->defaultValue(0),
            'storage_v2' => $this->smallInteger()->notNull()->defaultValue(0), 'economy_tick' => $this->smallInteger()->notNull()->defaultValue(0),
            'schema_version' => $this->integer()->notNull()->defaultValue(1), 'PRIMARY KEY ([[id]])',
        ]);
        $this->foreign('fk_world_registry_root', 'world_registry', 'active_world_id', 'world_node');
        $this->table('world_node_closure', [
            'ancestor_id' => $this->integer()->notNull(), 'descendant_id' => $this->integer()->notNull(),
            'distance' => $this->integer()->notNull(), 'PRIMARY KEY ([[ancestor_id]], [[descendant_id]])',
        ]);
        $this->index('idx_world_closure_descendant', 'world_node_closure', ['descendant_id', 'distance']);
        foreach (['ancestor_id', 'descendant_id'] as $column) $this->foreign('fk_world_closure_' . $column, 'world_node_closure', $column, 'world_node');
        $details = [
            'world_region' => ['climate' => $this->string(24)->notNull()->defaultValue('temperate')],
            'world_settlement' => ['settlement_kind' => $this->string(16)->notNull()->defaultValue('village'), 'population' => $this->integer()->notNull()->defaultValue(0), 'plot_limit' => $this->integer()->notNull()->defaultValue(64)],
            'world_building' => ['level' => $this->integer()->notNull()->defaultValue(1), 'condition' => $this->integer()->notNull()->defaultValue(100), 'max_condition' => $this->integer()->notNull()->defaultValue(100), 'operational_status' => $this->string(20)->notNull()->defaultValue('planned'), 'active_project_id' => $this->integer()],
            'world_room' => ['area' => $this->integer()->notNull()->defaultValue(1), 'exposure_class' => $this->string(16)->notNull()->defaultValue('indoor')],
            'world_plot' => ['plot_kind' => $this->string(24)->notNull()->defaultValue('land'), 'area' => $this->integer()->notNull()->defaultValue(1), 'fertility' => $this->integer()->notNull()->defaultValue(100), 'allow_building' => $this->smallInteger()->notNull()->defaultValue(0)],
            'world_bed' => ['garden_node_id' => $this->integer()->notNull(), 'ordinal' => $this->integer()->notNull(), 'unlocked' => $this->smallInteger()->notNull()->defaultValue(0)],
        ];
        foreach ($details as $table => $columns) {
            $this->table($table, array_merge(['node_id' => $this->integer()->notNull(), 'template_revision_id' => $this->integer()], $columns, ['PRIMARY KEY ([[node_id]])']));
            $this->foreign('fk_' . $table . '_node', $table, 'node_id', 'world_node');
            $this->foreign('fk_' . $table . '_template', $table, 'template_revision_id', 'world_template_revision');
        }
        $this->index('ux_world_bed_ordinal', 'world_bed', ['garden_node_id', 'ordinal'], true);
        $this->foreign('fk_world_bed_garden', 'world_bed', 'garden_node_id', 'world_plot', 'node_id');
        $this->table('world_audit', [
            'id' => $this->primaryKey(), 'actor_user_id' => $user, 'node_id' => $this->integer()->notNull(),
            'action' => $this->string(64)->notNull(), 'reason' => $this->string(255)->notNull(), 'before_json' => $this->text(), 'after_json' => $this->text(),
            'operation_id' => $this->string(32)->notNull(), 'created_at' => $this->integer()->notNull(),
        ]);
        $this->index('idx_world_audit_node', 'world_audit', ['node_id', 'id']);
        $this->foreign('fk_world_audit_user', 'world_audit', 'actor_user_id', 'user');
        $this->foreign('fk_world_audit_node', 'world_audit', 'node_id', 'world_node');
        $this->table('world_construction', [
            'id' => $this->primaryKey(), 'node_id' => $this->integer()->notNull(), 'owner_user_id' => $user . ' NOT NULL',
            'template_revision_id' => $this->integer()->notNull(), 'status' => $this->string(20)->notNull(), 'started_at' => $this->integer()->notNull(),
            'finish_at' => $this->integer()->notNull(), 'paused_at' => $this->integer(), 'operation_id' => $this->string(32)->notNull(),
            'terms_json' => $this->text()->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1),
        ]);
        $this->index('ux_world_construction_operation', 'world_construction', ['operation_id'], true);
        $this->index('idx_world_construction_due', 'world_construction', ['status', 'finish_at', 'id']);
        $this->foreign('fk_world_construction_node', 'world_construction', 'node_id', 'world_node');
        $this->foreign('fk_world_construction_owner', 'world_construction', 'owner_user_id', 'user');
        $this->foreign('fk_world_construction_template', 'world_construction', 'template_revision_id', 'world_template_revision');
        $this->foreign('fk_world_building_project', 'world_building', 'active_project_id', 'world_construction');
        $this->table('game_actor', ['id' => $this->primaryKey(), 'kind' => $this->string(16)->notNull(), 'user_id' => $user, 'revision' => $this->integer()->notNull()->defaultValue(1)]);
        $this->index('ux_game_actor_user', 'game_actor', ['user_id'], true);
        $this->foreign('fk_game_actor_user', 'game_actor', 'user_id', 'user');
        $this->table('world_membership', ['id' => $this->primaryKey(), 'user_id' => $user . ' NOT NULL', 'world_id' => $this->integer()->notNull(), 'starter_site_id' => $this->integer()->notNull(), 'joined_at' => $this->integer()->notNull(), 'grace_until' => $this->integer()->notNull()]);
        $this->index('ux_world_membership', 'world_membership', ['user_id', 'world_id'], true);
        $this->foreign('fk_world_membership_user', 'world_membership', 'user_id', 'user');
        $this->foreign('fk_world_membership_world', 'world_membership', 'world_id', 'world_node');
        $this->foreign('fk_world_membership_site', 'world_membership', 'starter_site_id', 'world_node');
        $this->db->schema->refresh();
        $this->db->transaction(function () {
            if (!(new \yii\db\Query())->from('world_registry')->where(['id' => 1])->exists($this->db)) $this->insert('world_registry', ['id' => 1]);
        });
    }
}
