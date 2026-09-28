<?php
use common\services\game\WorldDomainMigration;
use yii\db\Query;

/** Permanent map rights and optional absolute-coordinate footprints. */
class m260928_004000_world_map_cells extends WorldDomainMigration
{
    public function safeUp()
    {
        $node = $this->db->schema->getTableSchema('world_node', true);
        if (!isset($node->columns['footprint_json'])) $this->addColumn('world_node', 'footprint_json', $this->text());
        $this->domain('world_map_cell', [
            'x' => $this->integer()->notNull(), 'y' => $this->integer()->notNull(),
            'state' => $this->string(16)->notNull(), 'price' => $this->decimal(19, 4)->notNull()->defaultValue('0.0000'),
            'created_at' => $this->integer()->notNull(), 'updated_at' => $this->integer()->notNull(),
        ], ['parent_id' => ['world_node'], 'operation_id' => ['game_operation', 'id', false]], [['parent_id', 'x', 'y']], [['parent_id', 'state']]);
        // The migration is restartable on MySQL: do not overwrite discovered/purchased cells.
        $last = 0; $occupied = [];
        while (true) {
            $rows = (new Query())->select(['id', 'parent_id', 'node_type', 'status', 'position_x', 'position_y'])->from('world_node')
                ->where(['and', ['>', 'id', $last], ['not', ['parent_id' => null]]])->orderBy(['id' => SORT_ASC])->limit(500)->all($this->db);
            if (!$rows) break;
            foreach ($rows as $row) {
                $last = (int)$row['id'];
                if ($row['status'] === 'archived') continue;
                $parent = (int)$row['parent_id']; $x = (int)$row['position_x']; $y = (int)$row['position_y'];
                if (isset($occupied[$parent][$x . ':' . $y])) {
                    for ($position = 0; $position < 100000; $position++) {
                        $x = $position % 316; $y = intdiv($position, 316);
                        if (!isset($occupied[$parent][$x . ':' . $y])) break;
                    }
                    if ($position === 100000) throw new \RuntimeException('World map coordinates exhausted.');
                    $this->db->createCommand()->update('world_node', ['position_x' => $x, 'position_y' => $y, 'revision' => new \yii\db\Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $row['id']])->execute();
                }
                $occupied[$parent][$x . ':' . $y] = true;
                if ($row['node_type'] === 'BED' && !(new Query())->from('world_bed')->where(['node_id' => $row['id'], 'unlocked' => 1])->exists($this->db)) continue;
                $where = ['parent_id' => $parent, 'x' => $x, 'y' => $y];
                if ((new Query())->from('world_map_cell')->where($where)->exists($this->db)) continue;
                $this->db->createCommand()->insert('world_map_cell', $where + [
                    'state' => 'open', 'price' => '0.0000', 'operation_id' => null, 'created_at' => time(), 'updated_at' => time(),
                ])->execute();
            }
        }
    }
}
