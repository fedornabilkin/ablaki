<?php
namespace common\modules\world\service;

use common\services\game\CanonicalJson;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Query;

class WorldSeeder
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function seed(): array
    {
        return $this->db->transaction(function () {
            (new Locks($this->db))->row('craft_meta', ['id' => 1]);
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            \common\modules\craft\service\StorageMaintenance::writable($this->db);
            ShelterCatalog::seed($this->db);
            StarterGrantCatalog::seed($this->db);
            CultivationCatalog::seed($this->db);
            ExplorationCatalog::seed($this->db);
            $tree = new WorldTree($this->db); $ids = [];
            $nodes = [
                ['ablaki', null, 'WORLD', 'Мир Аблаки', 0, 0, []],
                ['north', 'ablaki', 'REGION', 'Северный край', 0, 0, ['climate' => 'cold']],
                ['south', 'ablaki', 'REGION', 'Южный край', 4, 3, ['climate' => 'temperate']],
                ['north-city', 'north', 'SETTLEMENT', 'Североград', 0, 0, ['settlement_kind' => 'city']],
                ['north-village', 'north', 'SETTLEMENT', 'Сосновка', 3, 3, ['settlement_kind' => 'village']],
                ['south-city', 'south', 'SETTLEMENT', 'Солнечный', 0, 0, ['settlement_kind' => 'city']],
                ['south-village', 'south', 'SETTLEMENT', 'Луговое', 3, 3, ['settlement_kind' => 'village']],
            ];
            foreach ($nodes as $position => $spec) {
                $existing = (new Query())->from('world_node')->where(['code' => $spec[0]])->one($this->db);
                if (!$existing) $existing = $tree->create(['code' => $spec[0], 'slug' => $spec[0], 'parent_id' => $spec[1] ? $ids[$spec[1]] : null,
                    'node_type' => $spec[2], 'name' => $spec[3], 'position_x' => $spec[4], 'position_y' => $spec[5], 'position' => $position], $spec[6]);
                elseif ($existing['node_type'] !== $spec[2]) throw new \RuntimeException('Seed code is occupied by another node type: ' . $spec[0]);
                $ids[$spec[0]] = (int)$existing['id'];
            }
            foreach (require dirname(__DIR__) . '/data/templates.php' as $template) {
                $existing = (new Query())->from('world_template')->where(['code' => $template['code']])->one($this->db);
                if ($existing) continue; // Published/admin content is never overwritten by a deploy.
                $this->db->createCommand()->insert('world_template', ['code' => $template['code'], 'kind' => $template['kind']])->execute();
                $this->db->createCommand()->insert('world_template_revision', ['template_id' => (int)$this->db->getLastInsertID(), 'version' => 1, 'status' => 'published', 'config_json' => CanonicalJson::encode($template['config']), 'published_at' => time()])->execute();
            }
            $this->db->createCommand()->update('world_registry', ['active_world_id' => $ids['ablaki']], ['id' => 1, 'active_world_id' => null])->execute();
            return $ids;
        });
    }
}
