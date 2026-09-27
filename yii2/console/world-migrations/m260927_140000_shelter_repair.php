<?php
use common\services\game\WorldMigration;
use common\services\game\Locks;
use yii\db\Query;

class m260927_140000_shelter_repair extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_shelter_protection', [
            'id' => $this->primaryKey(), 'deployment_id' => $this->reference('world_shelter_deployment') . ' NOT NULL', 'active_deployment_id' => $this->reference('world_shelter_deployment'),
            'started_at' => $this->integer()->notNull(), 'ended_at' => $this->integer(), 'protected_until' => $this->integer()->notNull(),
            'durability_at_start' => $this->integer()->notNull(), 'wear_remainder' => $this->integer()->notNull(),
            'daily_wear' => $this->integer()->notNull(), 'policy_version' => $this->integer()->notNull(), 'operation_id' => $this->reference('game_operation') . ' NOT NULL',
        ]);
        $this->index('ux_shelter_protection_current', 'world_shelter_protection', ['active_deployment_id'], true);
        $this->index('ux_shelter_protection_operation', 'world_shelter_protection', ['operation_id'], true);
        $this->index('idx_shelter_protection_history', 'world_shelter_protection', ['deployment_id', 'started_at', 'id']);
        $this->table('world_shelter_repair', [
            'id' => $this->primaryKey(), 'instance_id' => $this->reference('craft_equipment_instance') . ' NOT NULL', 'operation_id' => $this->reference('game_operation') . ' NOT NULL',
            'durability_before' => $this->integer()->notNull(), 'durability_after' => $this->integer()->notNull(),
            'terms_json' => $this->text()->notNull(), 'repaired_at' => $this->integer()->notNull(),
        ]);
        $this->index('ux_shelter_repair_operation', 'world_shelter_repair', ['operation_id'], true);
        $this->index('idx_shelter_repair_instance', 'world_shelter_repair', ['instance_id', 'id']);
        $n = 0;
        foreach ([
            'world_shelter_protection' => ['deployment_id' => 'world_shelter_deployment', 'active_deployment_id' => 'world_shelter_deployment', 'operation_id' => 'game_operation'],
            'world_shelter_repair' => ['instance_id' => 'craft_equipment_instance', 'operation_id' => 'game_operation'],
        ] as $table => $columns) foreach ($columns as $column => $parent) $this->foreign('fk_shelter_repair_' . ++$n, $table, $column, $parent);
        // All DDL is finished before MySQL data transactions. Historical expiry is copied unchanged.
        $after = 0;
        do {
            $rows = $this->db->transaction(function () use ($after) {
                $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
                $rows = (new Query())->from('world_shelter_deployment')->where(['>', 'id', $after])->orderBy(['id' => SORT_ASC])->limit(100)->all($this->db);
                foreach ($rows as $row) {
                    if ((new Query())->from('world_shelter_protection')->where(['operation_id' => $row['operation_id']])->exists($this->db)) continue;
                    $values = array_intersect_key($row, array_flip(['started_at', 'ended_at', 'protected_until', 'durability_at_start', 'wear_remainder', 'daily_wear', 'policy_version', 'operation_id']));
                    $this->db->createCommand()->insert('world_shelter_protection', $values + ['deployment_id' => $row['id'], 'active_deployment_id' => $row['ended_at'] === null ? $row['id'] : null])->execute();
                }
                return $rows;
            });
            if ($rows) $after = (int)end($rows)['id'];
        } while (count($rows) === 100);
    }
}
