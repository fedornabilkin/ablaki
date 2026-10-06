<?php
use common\modules\world\support\WorldDomainMigration;
use common\modules\world\models\migration\ObjectTableMerge;
use common\modules\world\models\migration\SimpleWorldSeed;

/** DDL precedes data transactions on MySQL; every schema step can be resumed. */
class m261005_100000_simple_world extends WorldDomainMigration
{
    private function column(string $table, string $name, $type): void
    {
        if (!isset($this->db->schema->getTableSchema($table, true)->columns[$name])) $this->addColumn($table, $name, $type);
    }
    public function safeUp()
    {
        foreach (['name' => $this->string(120), 'hierarchy_level' => $this->smallInteger(),
            'build_seconds' => $this->integer()->notNull()->defaultValue(60), 'enabled' => $this->smallInteger()->notNull()->defaultValue(1),
            'player_buildable' => $this->smallInteger()->notNull()->defaultValue(0),
            'materials_json' => $this->text(), 'defaults_json' => $this->text()] as $name => $type) $this->column('world_template', $name, $type);
        $this->domain('world_template_parent', [], ['template_id' => ['world_template'], 'parent_template_id' => ['world_template']], [], [], ['template_id', 'parent_template_id']);
        $this->column('world_node', 'template_id', $this->integer());
        $this->column('world_node', 'hierarchy_level', $this->smallInteger());
        $this->column('world_node', 'settings_json', $this->text());
        $this->foreign('fk_world_node_template', 'world_node', 'template_id', 'world_template');
        $this->index('ix_world_node_level_template', 'world_node', ['hierarchy_level', 'template_id', 'status', 'id']);
        $this->domain('world_build', [
            'required_seconds' => $this->integer()->notNull(), 'worked_seconds' => $this->integer()->notNull()->defaultValue(0),
            'labor_budget' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'), 'commitment_id' => $this->integer(),
            'materials_json' => $this->text()->notNull(), 'status' => $this->string(16)->notNull()->defaultValue('building'),
            'revision' => $this->integer()->notNull()->defaultValue(1), 'created_at' => $this->integer()->notNull(), 'finished_at' => $this->integer(),
        ], ['node_id' => ['world_node'], 'parent_id' => ['world_node'], 'owner_user_id' => ['user']], [['node_id']], [['status', 'id']]);
        $this->foreign('fk_world_build_commitment', 'world_build', 'commitment_id', 'economy_spending_commitment');
        $this->domain('world_build_work', [
            'started_at' => $this->integer()->notNull(), 'last_seen_at' => $this->integer()->notNull(),
            'seconds' => $this->integer()->notNull()->defaultValue(0), 'ended_at' => $this->integer(),
            'paid_amount' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'),
        ], ['build_id' => ['world_build'], 'user_id' => ['user']], [], [['build_id', 'user_id', 'id']]);
        $this->column('world_build_work', 'accounted_at', $this->integer()->notNull()->defaultValue(0));
        $this->db->createCommand()->update('world_build_work', ['accounted_at' => new \yii\db\Expression('[[last_seen_at]]')], ['accounted_at' => 0])->execute();
        $this->domain('world_builder', [], ['user_id' => ['user'], 'work_id' => ['world_build_work']], [['work_id']], [], ['user_id']);
        $this->domain('world_start_claim', ['created_at' => $this->integer()->notNull()], ['user_id' => ['user']], [], [], ['user_id']);
        $this->column('economy_transfer', 'destination_user_id', $this->reference('user'));
        $destination = $this->db->schema->getTableSchema('economy_transfer', true)->columns['destination_account_id'];
        if (!$destination->allowNull) $this->nullable('economy_transfer', 'destination_account_id');
        $this->foreign('fk_economy_transfer_destination_user', 'economy_transfer', 'destination_user_id', 'user');
        $this->column('world_event', 'name', $this->string(120)->notNull()->defaultValue('Событие'));
        $this->column('world_event', 'description', $this->text());
        $this->nullable('world_event', 'template_revision_id');
        $this->nullable('world_event', 'operation_id');
        $this->nullable('world_housing_place', 'purchase_id');
        // Existing events retain their immutable template/operation links.
        $this->db->schema->refresh();
        (new ObjectTableMerge($this->db))->run();
        $this->db->transaction(function () { (new SimpleWorldSeed($this->db))->run(); });
    }
    private function nullable(string $table, string $column): void
    {
        $schema = $this->db->schema->getTableSchema($table, true);
        if ($schema->columns[$column]->allowNull) return;
        if ($this->db->driverName !== 'sqlite') {
            $this->alterColumn($table, $column, $schema->columns[$column]->dbType . ' NULL'); return;
        }
        // SQLite has no ALTER COLUMN; this path is used only by isolated fixtures.
        $sql = $this->db->createCommand("SELECT sql FROM sqlite_master WHERE type='table' AND name=:name", [':name' => $table])->queryScalar();
        $indexes = $this->db->createCommand("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name=:name AND sql IS NOT NULL", [':name' => $table])->queryColumn();
        $sql = preg_replace('/([`"\[]?' . preg_quote($column, '/') . '[`"\]]?\s+[^,]*?)\s+NOT NULL/i', '$1', $sql);
        $this->renameTable($table, $table . '_nullable_copy');
        $this->execute($sql);
        $this->execute('INSERT INTO [[' . $table . ']] SELECT * FROM [[' . $table . '_nullable_copy]]');
        $this->dropTable($table . '_nullable_copy');
        foreach ($indexes as $index) $this->execute($index);
        $this->db->schema->refresh();
    }
}
