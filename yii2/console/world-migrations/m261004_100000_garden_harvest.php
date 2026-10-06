<?php
use common\modules\world\support\WorldDomainMigration;
use common\modules\world\models\domain\GardenHarvest;
use common\modules\world\models\domain\GardenTools;
use yii\db\Query;
use yii\db\Expression;

class m261004_100000_garden_harvest extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_harvest_lot', ['harvested_at' => $this->bigInteger()->notNull(), 'spoiled_at' => $this->bigInteger()->null(), 'price' => $this->decimal(19, 4)->null()],
            ['inventory_id' => ['craft_inventory'], 'operation_id' => ['game_operation']], [], [], ['inventory_id']);
        $this->db->transaction(function () {
            GardenTools::seed($this->db);
            foreach ((new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['p' => 'world_plot'], '[[p.node_id]]=[[n.id]]')->where(['p.plot_kind' => 'garden'])->all($this->db) as $garden) {
                if ($garden['owner_user_id']) GardenHarvest::provision($this->db, (int)$garden['id'], (int)$garden['owner_user_id']);
                $beds = (new Query())->select(['n.*', 'b.ordinal'])->from(['n' => 'world_node'])->innerJoin(['b' => 'world_bed'], '[[b.node_id]]=[[n.id]]')->where(['n.parent_id' => $garden['id']])->orderBy(['b.ordinal' => SORT_ASC])->all($this->db);
                if (!$beds) continue;
                $positions = [];
                foreach ($beds as $bed) $positions[$bed['position_x'] . ':' . $bed['position_y']] = ['x' => ((int)$bed['ordinal'] - 1) % 5 - 2, 'y' => (int)$bed['ordinal'] <= 5 ? 0 : -1];
                $cells = (new Query())->from('world_map_cell')->where(['parent_id' => $garden['id']])->all($this->db);
                foreach ($cells as $cell) if (!isset($positions[$cell['x'] . ':' . $cell['y']])) throw new \RuntimeException('Garden contains a cell unrelated to a bed: ' . $garden['id']);
                // Preserve every cell ID, payment and entitlement while vacating unique coordinates.
                $this->db->createCommand()->delete('world_map_cell', ['parent_id' => $garden['id']])->execute();
                foreach ($cells as $cell) {
                    $point = $positions[$cell['x'] . ':' . $cell['y']]; $cell['x'] = $point['x']; $cell['y'] = $point['y'];
                    $this->db->createCommand()->insert('world_map_cell', $cell)->execute();
                }
                foreach ($beds as $bed) {
                    $point = $positions[$bed['position_x'] . ':' . $bed['position_y']];
                    $this->db->createCommand()->update('world_node', ['position_x' => $point['x'], 'position_y' => $point['y'], 'revision' => new Expression('[[revision]]+1')], ['id' => $bed['id']])->execute();
                }
                $this->db->createCommand()->update('world_node', ['map_width' => 5, 'map_height' => 2, 'map_origin_x' => -2, 'map_origin_y' => -1, 'revision' => new Expression('[[revision]]+1')], ['id' => $garden['id']])->execute();
            }
        });
    }
}
