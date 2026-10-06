<?php
namespace common\modules\world\models\migration;

use yii\db\Connection;
use yii\db\Query;
use yii\helpers\Json;

/** Stable identities. Existing objects, ownership, inventory and balances are retained. */
final class SimpleWorldSeed
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function run(): void
    {
        $specs = require dirname(__DIR__, 2) . '/data/simple-templates.php'; $ids = []; $configured = [];
        foreach ($specs as $code => $spec) {
            $row = (new Query())->from('world_template')->where(['code' => $code])->one($this->db);
            $configured[$code] = !$row || $row['hierarchy_level'] === null;
            $values = ['name' => $spec[0], 'kind' => $spec[1], 'hierarchy_level' => $spec[2], 'build_seconds' => $spec[4],
                'materials_json' => Json::encode((object)$spec[5]), 'defaults_json' => Json::encode((object)$spec[6]), 'enabled' => 1, 'player_buildable' => (int)($spec[2] > 3)];
            if (!$row) {
                $this->db->createCommand()->insert('world_template', ['code' => $code] + $values)->execute();
                $row = ['id' => $this->db->getLastInsertID()];
            } elseif ($row['hierarchy_level'] === null) $this->db->createCommand()->update('world_template', $values, ['id' => $row['id']])->execute();
            $ids[$code] = (int)$row['id'];
        }
        foreach ($specs as $code => $spec) foreach ($spec[3] as $parent) {
            if (!$configured[$code]) continue;
            $pair = ['template_id' => $ids[$code], 'parent_template_id' => $ids[$parent]];
            if (!(new Query())->from('world_template_parent')->where($pair)->exists($this->db)) $this->db->createCommand()->insert('world_template_parent', $pair)->execute();
        }
        // Existing non-start templates remain available for historical rows only until configured.
        $this->db->createCommand()->update('world_template', ['enabled' => 0], ['hierarchy_level' => null])->execute();
        $this->normalize($ids);
        SimpleContent::seed($this->db);
    }
    private function normalize(array $ids): void
    {
        $rows = (new Query())->from('world_node')->orderBy(['depth' => SORT_ASC, 'id' => SORT_ASC])->all($this->db);
        foreach ($rows as $row) {
            if ($row['template_id'] !== null) continue;
            $map = ['WORLD' => 'world', 'REGION' => 'region', 'SETTLEMENT' => 'city', 'ROOM' => 'bedroom', 'BED' => 'garden-bed', 'PLACE' => 'place', 'CHEST' => 'chest'];
            $code = $map[$row['node_type']] ?? null;
            if ($row['node_type'] === 'PLOT') $code = $row['plot_kind'] === 'garden' ? 'garden' : 'starter-site';
            if ($row['node_type'] === 'BUILDING') $code = in_array($row['building_kind'], ['house', 'forge', 'mine'], true) ? $row['building_kind'] : ($row['building_kind'] === 'shelter' ? 'starter-shelter' : 'house');
            if (!$code) throw new \RuntimeException('Unknown object type #' . $row['id']);
            $template = (new Query())->from('world_template')->where(['id' => $ids[$code]])->one($this->db);
            $parent = $row['parent_id'] ? (new Query())->from('world_node')->where(['id' => $row['parent_id']])->one($this->db) : null;
            // Former buildings/gardens directly in towns get an explicit estate without changing ownership.
            if ((int)$template['hierarchy_level'] === 5 && $parent && $parent['node_type'] === 'SETTLEMENT') {
                $parent = $this->legacyEstate($row, $parent, $ids['starter-site']);
            }
            if ((int)$template['hierarchy_level'] === 5 && $parent && $parent['node_type'] === 'BUILDING') {
                $parent = (new Query())->from('world_node')->where(['id' => $parent['parent_id']])->one($this->db);
            }
            if ($parent && (int)$parent['hierarchy_level'] + 1 !== (int)$template['hierarchy_level'])
                throw new \RuntimeException('Object #' . $row['id'] . ' requires explicit hierarchy repair; migration rolled back.');
            // Preserve unusual historical subtypes with their own explicit, non-buildable template.
            $permitted = !$parent || (new Query())->from('world_template_parent')->where(['template_id' => $template['id'], 'parent_template_id' => $parent['template_id']])->exists($this->db);
            if (!$permitted) $template['id'] = $this->historicalTemplate($row, $template, $parent);
            $this->db->createCommand()->update('world_node', ['template_id' => $template['id'], 'hierarchy_level' => $template['hierarchy_level'],
                'depth' => (int)$template['hierarchy_level'] - 1, 'parent_id' => $parent ? $parent['id'] : null, 'settings_json' => '{}'], ['id' => $row['id']])->execute();
        }
        $this->rebuildClosure();
    }
    private function legacyEstate(array $node, array $city, int $template): array
    {
        $code = 'legacy-estate-' . $node['id'];
        $existing = (new Query())->from('world_node')->where(['code' => $code])->one($this->db);
        if ($existing) return $existing;
        $values = ['code' => $code, 'slug' => $code, 'name' => 'Усадьба: ' . mb_substr($node['name'], 0, 100), 'label' => 'Усадьба',
            'node_type' => 'PLOT', 'parent_id' => $city['id'], 'root_id' => $city['root_id'], 'template_id' => $template,
            'owner_user_id' => $node['owner_user_id'], 'hierarchy_level' => 4, 'depth' => 3, 'status' => $node['status'], 'visibility' => $node['visibility'],
            'plot_kind' => 'campsite', 'allow_building' => 1, 'area' => 25, 'position_x' => $node['position_x'], 'position_y' => $node['position_y'],
            'map_width' => 1000, 'map_height' => 1000, 'map_origin_x' => -500, 'map_origin_y' => -500,
            'revision' => 1, 'created_at' => time(), 'updated_at' => time(), 'settings_json' => '{}'];
        $this->db->createCommand()->insert('world_node', $values)->execute(); $values['id'] = (int)$this->db->getLastInsertID();
        return $values;
    }
    private function historicalTemplate(array $node, array $template, array $parent): int
    {
        $code = 'legacy-' . $template['code'] . '-' . $parent['template_id'];
        $row = (new Query())->from('world_template')->where(['code' => $code])->one($this->db);
        if ($row) return (int)$row['id'];
        unset($template['id']); $template['code'] = $code; $template['player_buildable'] = 0;
        $this->db->createCommand()->insert('world_template', $template)->execute(); $id = (int)$this->db->getLastInsertID();
        $this->db->createCommand()->insert('world_template_parent', ['template_id' => $id, 'parent_template_id' => $parent['template_id']])->execute();
        return $id;
    }
    private function rebuildClosure(): void
    {
        $nodes = (new Query())->from('world_node')->indexBy('id')->all($this->db);
        $this->db->createCommand()->delete('world_node_closure')->execute();
        foreach ($nodes as $id => $row) {
            $cursor = $id; $seen = []; $distance = 0;
            while ($cursor !== null) {
                if (isset($seen[$cursor]) || !isset($nodes[$cursor]) || $distance > 6) throw new \RuntimeException('Invalid tree at #' . $id);
                $seen[$cursor] = true;
                $this->db->createCommand()->insert('world_node_closure', ['ancestor_id' => $cursor, 'descendant_id' => $id, 'distance' => $distance++])->execute();
                $cursor = $nodes[$cursor]['parent_id'];
            }
        }
    }
}
