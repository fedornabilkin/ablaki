<?php
use common\modules\world\support\WorldMigration;
use common\modules\world\models\domain\ExplorationCatalog;
use common\modules\world\models\domain\WorldMapGeometry;
use yii\db\Query;
use yii\db\Expression;

/** Data only: atomic on MySQL/MariaDB too; no DDL inside the transaction. */
class m261003_150000_world_exploration extends WorldMigration
{
    public function safeUp()
    {
        $this->db->transaction(function () {
            ExplorationCatalog::seed($this->db);
            foreach ((new Query())->from('world_node')->all($this->db) as $parent) {
                $children = (new Query())->from('world_node')->where(['parent_id' => $parent['id']])->orderBy(['id' => SORT_ASC])->all($this->db);
                $garden = (new Query())->from('world_plot')->where(['node_id' => $parent['id'], 'plot_kind' => 'garden'])->exists($this->db);
                $active = array_values(array_filter($children, static function ($n) { return $n['status'] !== 'archived'; }));
                $dx = $active && !$garden ? (int)$active[0]['position_x'] : (int)$parent['map_origin_x'] + intdiv((int)$parent['map_width'], 2);
                $dy = $active && !$garden ? (int)$active[0]['position_y'] : (int)$parent['map_origin_y'] + intdiv((int)$parent['map_height'], 2);
                $points = []; $width = (int)$parent['map_width']; $height = (int)$parent['map_height'];
                foreach ($children as $child) {
                    $polygon = $child['footprint_json'] === null ? null : json_decode($child['footprint_json'], true);
                    if ($polygon) foreach ($polygon as &$point) { $point['x'] -= $dx; $point['y'] -= $dy; } unset($point);
                    $values = ['position_x' => (int)$child['position_x'] - $dx, 'position_y' => (int)$child['position_y'] - $dy, 'footprint_json' => $polygon ? json_encode($polygon) : null, 'revision' => new Expression('[[revision]]+1')];
                    if ($dx || $dy) $this->db->createCommand()->update('world_node', $values, ['id' => $child['id']])->execute();
                    if ($child['status'] !== 'archived') $points = array_merge($points, WorldMapGeometry::cells($values['footprint_json'], $values['position_x'], $values['position_y']));
                }
                $cells = (new Query())->from('world_map_cell')->where(['parent_id' => $parent['id']])->all($this->db);
                // Vacate unique coordinates together, retaining every cell ID, price and operation.
                if (($dx || $dy) && $cells) $this->db->createCommand()->delete('world_map_cell', ['parent_id' => $parent['id']])->execute();
                foreach ($cells as $cell) {
                    $cell['x'] = (int)$cell['x'] - $dx; $cell['y'] = (int)$cell['y'] - $dy; $points[] = $cell;
                    if ($dx || $dy) $this->db->createCommand()->insert('world_map_cell', $cell)->execute();
                }
                foreach ($points as $point) {
                    $width = max($width, $point['x'] < 0 ? -2 * $point['x'] : 2 * $point['x'] + 1);
                    $height = max($height, $point['y'] < 0 ? -2 * $point['y'] : 2 * $point['y'] + 1);
                }
                if ($width > 1000 || $height > 1000) throw new \RuntimeException('Map cannot be centred within 1000 cells: ' . $parent['id']);
                $this->db->createCommand()->update('world_node', ['map_width' => $width, 'map_height' => $height, 'map_origin_x' => -intdiv($width, 2), 'map_origin_y' => -intdiv($height, 2), 'revision' => new Expression('[[revision]]+1')], ['id' => $parent['id']])->execute();
            }
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
        });
    }
}
