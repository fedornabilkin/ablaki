<?php
namespace common\modules\world\service;

use common\services\game\CanonicalJson;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Initial test content only. Existing published/admin-edited content is retained. */
class TestWorldDefaults
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    private function operation(): string
    {
        $id = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('game_operation', ['id' => $id, 'user_id' => null, 'type' => 'world.test.defaults', 'created_at' => time()])->execute();
        return $id;
    }
    public static function initialPolicy(Connection $db, int $subject, string $operation): void
    {
        if (getenv('WORLD_TEST_MODE') !== '1') return;
        if (!$db->getTransaction()) throw new \LogicException('Test policy requires the provisioning transaction.');
        $old = (new Query())->select('h.*')->from(['r' => 'economy_parent_rule'])->innerJoin(['h' => 'economy_parent_history'], '[[h.id]]=[[r.history_id]]')->where(['r.subject_id' => $subject])->one($db);
        if (!$old || !in_array($old['status'], ['root', 'unconfigured'], true)) return;
        $now = time(); $revision = (int)$old['revision'] + 1;
        if ($db->createCommand()->update('economy_parent_history', ['effective_to' => $now], ['id' => $old['id'], 'effective_to' => null])->execute() !== 1) throw new \RuntimeException('Initial test policy changed.');
        $db->createCommand()->insert('economy_parent_history', ['subject_id' => $subject, 'parent_subject_id' => $old['parent_subject_id'], 'revision' => $revision, 'status' => 'published', 'basis' => 'collected_revenue',
            'rate_bps' => $old['parent_subject_id'] === null ? 0 : 1000, 'due_seconds' => 86400, 'effective_from' => $now, 'effective_to' => null, 'operation_id' => $operation, 'reason' => 'Initial test-world policy'])->execute();
        $history = (int)$db->getLastInsertID();
        $db->createCommand()->insert('economy_collection_policy', ['history_id' => $history, 'protected_seconds' => 3600, 'loss_period_seconds' => 3600, 'loss_rate_bps' => 100])->execute();
        $db->createCommand()->insert('economy_tax_checkpoint', ['history_id' => $history, 'fraction' => 0])->execute();
        if ($db->createCommand()->update('economy_parent_rule', ['history_id' => $history, 'revision' => $revision], ['subject_id' => $subject, 'history_id' => $old['id']])->execute() !== 1) throw new \RuntimeException('Initial test policy pointer changed.');
    }
    public function seed(): void
    {
        TestWorldSetup::requireContext();
        $this->db->transaction(function () {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $operation = $this->operation();
            foreach ((new Query())->select(['s.id', 's.node_id'])->from(['s' => 'economy_subject'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[s.node_id]]')->where(['n.status' => 'active'])->all($this->db) as $subject) {
                self::initialPolicy($this->db, (int)$subject['id'], $operation);
            }
            foreach ((new Query())->select('id')->from('world_node')->where(['node_type' => 'SETTLEMENT', 'status' => 'active', 'owner_user_id' => null])->column($this->db) as $settlement) $this->offers((int)$settlement);
            $this->crop(); $this->professions();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
        });
    }
    private function offers(int $settlement): void
    {
        $premises = new WorldPremises($this->db, $this->flags, new WorldAccessPolicy(0));
        $raw = (new Query())->from('craft_item')->where(['kind' => 'material', 'active' => 1, 'storage_kind' => 'none'])->andWhere(['>', 'gather_quantity', 0])->orderBy(['id' => SORT_ASC])->one($this->db);
        $definitions = [
            'canopy' => ['name' => 'Навес', 'kind' => 'canopy', 'area' => 1, 'slots' => 1, 'price' => '20.0000'],
            'workroom' => ['name' => 'Мастерская', 'kind' => 'workroom', 'area' => 2, 'slots' => 1, 'expansion_limit' => 2, 'expansion_base_price' => '10.0000', 'price' => '40.0000'],
            'house' => ['name' => 'Дом', 'kind' => 'house', 'area' => 3, 'slots' => 1, 'expansion_limit' => 2, 'expansion_base_price' => '10.0000', 'price' => '100.0000'],
        ];
        if ($raw) $definitions['house-construction'] = $definitions['house'] + ['delivery' => 'construction', 'duration_seconds' => 300, 'materials' => [['item_id' => (int)$raw['id'], 'quantity' => 2]]];
        foreach ($definitions as $code => $input) {
            $key = 'test-premises-' . $settlement . '-' . $code;
            if ($this->one('world_template', ['code' => $key])) continue;
            $operation = $this->operation();
            if ($code === 'house-construction') $input['name'] = 'Дом — строительство';
            if ($raw) $input['repair'] = ['full_price' => '5.0000', 'materials' => [['item_id' => (int)$raw['id'], 'quantity' => 2]]];
            $config = $premises->publication($input) + ['exposure_class' => $input['kind'] === 'canopy' ? 'covered' : 'indoor'];
            $config['materials'] = (new ConstructionSpec($this->db))->resolvedMaterials($config['materials']);
            if ($config['repair'] !== null) $config['repair'] = (new BuildingRepairSpec($this->db))->resolve($config['repair']);
            $this->db->createCommand()->insert('world_template', ['code' => $key, 'kind' => 'BUILDING'])->execute(); $template = (int)$this->db->getLastInsertID();
            $this->db->createCommand()->insert('world_template_revision', ['template_id' => $template, 'version' => 1, 'status' => 'published', 'config_json' => CanonicalJson::encode($config), 'published_at' => time()])->execute(); $revision = (int)$this->db->getLastInsertID();
            $this->db->createCommand()->insert('world_premises_offer', ['settlement_id' => $settlement, 'template_revision_id' => $revision, 'name' => $input['name'], 'operation_id' => $operation, 'created_at' => time()])->execute();
        }
        // Never republish an offer which an administrator deliberately withdrew.
        if (!$this->one('world_garden_offer', ['settlement_id' => $settlement])) $this->db->createCommand()->insert('world_garden_offer', ['settlement_id' => $settlement, 'active_settlement_id' => $settlement, 'name' => 'Огород', 'price' => '30.0000', 'base_price' => '10.0000', 'operation_id' => $this->operation(), 'created_at' => time()])->execute();
    }
    private function crop(): void
    {
        $crop = $this->one('world_crop', ['code' => 'carrot']);
        if (!$crop || (new Query())->from('world_crop_revision')->where(['crop_id' => $crop['id'], 'status' => 'published'])->exists($this->db)) return;
        $draft = $this->one('world_crop_revision', ['crop_id' => $crop['id'], 'version' => 1, 'status' => 'draft']);
        if (!$draft || json_decode($draft['config_json'], true) !== ['requires_publication' => true]) return;
        $config = ['code' => 'carrot', 'name' => 'Морковь', 'reason' => 'Initial test-world crop', 'seed_item_id' => (int)$draft['seed_item_id'], 'yield_item_id' => (int)$draft['yield_item_id'], 'water_item_id' => (int)$draft['water_item_id'], 'seed_quantity' => 1, 'yield_quantity' => 3, 'grow_seconds' => 300, 'water_quantity' => 1, 'water_interval_seconds' => 120];
        $config = (new WorldCultivation($this->db, $this->flags, new WorldAccessPolicy(0)))->publication($config);
        $values = array_intersect_key($config, array_flip(['seed_quantity', 'yield_quantity', 'grow_seconds', 'water_quantity', 'water_interval_seconds']));
        $this->db->createCommand()->update('world_crop_revision', $values + ['status' => 'published', 'config_json' => CanonicalJson::encode($config), 'published_at' => time()], ['id' => $draft['id'], 'status' => 'draft'])->execute();
        // Seed acquisition uses the existing gathering action; nobody receives items on deploy.
        $this->db->createCommand()->update('craft_item', ['gather_quantity' => 1], ['id' => $draft['seed_item_id'], 'code' => 'world-carrot-seed', 'gather_quantity' => 0])->execute();
        $this->db->createCommand()->update('craft_meta', ['revision' => new Expression('[[revision]]+1')], ['id' => 1])->execute();
    }
    private function professions(): void
    {
        foreach ((new Query())->from('profession')->where(['status' => 'draft'])->all($this->db) as $profession) {
            if ((new Query())->from('profession_revision')->where(['profession_id' => $profession['id']])->exists($this->db)) continue;
            $source = $profession['code'] === 'builder' ? 'world.construction.finished' : ($profession['code'] === 'merchant' ? 'world.orders.deliver' : 'craft.completed');
            $config = ['code' => $profession['code'], 'name' => $profession['name'], 'reason' => 'Initial test-world progression', 'levels' => [], 'sources' => [$source => ['xp' => 10, 'daily_max' => 1000000]]];
            foreach ([0, 20, 50, 100, 200] as $xp) $config['levels'][] = ['required_xp' => $xp, 'achievements' => [], 'limits' => []];
            $config = (new \common\modules\progression\service\WorldProgression($this->db, $this->flags, new WorldAccessPolicy(0)))->publication($config);
            $this->db->createCommand()->insert('profession_revision', ['profession_id' => $profession['id'], 'version' => 1, 'status' => 'published', 'max_level' => 5, 'config_json' => CanonicalJson::encode($config), 'published_at' => time()])->execute(); $revision = (int)$this->db->getLastInsertID();
            foreach ($config['levels'] as $level) $this->db->createCommand()->insert('profession_level', ['revision_id' => $revision, 'level' => $level['level'], 'required_xp' => $level['required_xp'], 'limits_json' => '{}'])->execute();
            $this->db->createCommand()->insert('profession_current', ['profession_id' => $profession['id'], 'revision_id' => $revision])->execute();
            $this->db->createCommand()->update('profession', ['status' => 'published'], ['id' => $profession['id']])->execute();
        }
    }
}
