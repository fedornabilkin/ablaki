<?php
use common\services\game\WorldMigration;
use common\modules\world\service\WorldLayout;
use common\modules\world\service\WorldMapGeometry;
use yii\db\Query;

/** Additive and restartable after MySQL's implicit DDL commits. */
class m261003_120000_world_living_spaces extends WorldMigration
{
    public function safeUp()
    {
        $columns = [
            'world_node' => ['map_width' => $this->integer()->null(), 'map_height' => $this->integer()->null(), 'map_origin_x' => $this->integer()->notNull()->defaultValue(0), 'map_origin_y' => $this->integer()->notNull()->defaultValue(0)],
            'world_building' => ['building_kind' => $this->string(24)->notNull()->defaultValue('house')],
            'world_bed' => ['dug_at' => $this->bigInteger()->null()],
            'world_crop_revision' => ['water_window_seconds' => $this->integer()->notNull()->defaultValue(60), 'harvest_window_seconds' => $this->integer()->notNull()->defaultValue(86400)],
            'world_crop_cycle' => ['water_missed' => $this->boolean()->notNull()->defaultValue(false)],
        ];
        foreach ($columns as $table => $fields) foreach ($fields as $name => $type)
            if (!isset($this->db->schema->getTableSchema($table, true)->columns[$name])) $this->addColumn($table, $name, $type);
        $this->table('world_warehouse_policy', ['storage_id' => $this->integer()->notNull(), 'initial_capacity' => $this->integer()->notNull(), 'max_capacity' => $this->integer()->notNull(), 'base_price' => $this->decimal(19, 4)->notNull(), 'revision' => $this->integer()->notNull()->defaultValue(1), 'PRIMARY KEY ([[storage_id]])']);
        $this->foreign('fk_world_warehouse_storage', 'world_warehouse_policy', 'storage_id', 'craft_storage');
        foreach ((new Query())->from('world_node')->where(['map_width' => null])->all($this->db) as $node) {
            $details = $node['node_type'] === 'PLOT' ? (new Query())->from('world_plot')->where(['node_id' => $node['id']])->one($this->db) : [];
            $size = WorldLayout::defaults($node['node_type'], $details ?: []); $points = [];
            foreach ((new Query())->from('world_node')->where(['parent_id' => $node['id']])->andWhere(['<>', 'status', 'archived'])->all($this->db) as $child)
                $points = array_merge($points, WorldMapGeometry::cells($child['footprint_json'], (int)$child['position_x'], (int)$child['position_y']));
            $points = array_merge($points, (new Query())->select(['x', 'y'])->from('world_map_cell')->where(['parent_id' => $node['id']])->all($this->db));
            foreach ($points as $point) {
                $size['map_origin_x'] = min($size['map_origin_x'], (int)$point['x']); $size['map_origin_y'] = min($size['map_origin_y'], (int)$point['y']);
            }
            foreach ($points as $point) {
                $size['map_width'] = max($size['map_width'], (int)$point['x'] - $size['map_origin_x'] + 1);
                $size['map_height'] = max($size['map_height'], (int)$point['y'] - $size['map_origin_y'] + 1);
            }
            $this->update('world_node', $size, ['id' => $node['id'], 'map_width' => null]);
        }
        foreach ((new Query())->from('world_premises_purchase')->all($this->db) as $purchase) {
            $terms = json_decode($purchase['terms_json'], true);
            if (isset($terms['config']['kind'])) $this->update('world_building', ['building_kind' => $terms['config']['kind']], ['node_id' => $purchase['building_id']]);
        }
        foreach ((new Query())->select(['b.node_id', 'b.building_kind', 'n.owner_user_id'])->from(['b' => 'world_building'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[b.node_id]]')->where(['n.status' => 'active'])->andWhere(['not', ['n.owner_user_id' => null]])->all($this->db) as $building) {
            \common\modules\world\service\BuildingFacilities::stockpile($this->db, (int)$building['node_id'], (int)$building['owner_user_id'], $building['building_kind']);
        }
        (new \common\modules\economy\service\LeafFinanceMigration($this->db))->run();
    }
}
