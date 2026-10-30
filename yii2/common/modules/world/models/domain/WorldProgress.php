<?php
namespace common\modules\world\models\domain;

use yii\db\{Connection, Expression, Query};

/** Shared server facts for funded goals and the development guide. Reads never issue rewards. */
final class WorldProgress
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function quantities(int $user): array
    {
        $raw = (new Query())->from('world_economy_fact')->where(['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user])->indexBy('code')->all($this->db);
        // Crafting records recipe codes, which need not equal their output item codes.
        foreach ($raw as $code => $fact) if (strncmp($code, 'crafted:', 8) === 0) $raw['produced:' . substr($code, 8)] = $fact;
        // Reconcile players who joined or acquired items before the fact journal was introduced.
        // Use max, never addition: possessions and historical events may describe the same action.
        $world = EconomyRuntime::world($this->db); $existing = [];
        $member = (new Query())->from('world_membership')->where(['world_id' => $world, 'user_id' => $user])->one($this->db);
        if ($member) $existing['city_joined'] = 1;
        $owned = (new Query())->select(['n.id', 'n.status', 't.code', 'parent_code' => 'pt.code'])->from(['n' => 'world_node'])->leftJoin(['t' => 'world_template'], 't.id=n.template_id')
            ->leftJoin(['p' => 'world_node'], 'p.id=n.parent_id')->leftJoin(['pt' => 'world_template'], 'pt.id=p.template_id')
            ->where(['n.owner_user_id' => $user, 'n.root_id' => $world])->all($this->db);
        $ids = array_column($owned, 'id');
        foreach ($owned as $node) if ($node['status'] === 'active') {
            foreach (['built:' . $node['code'], 'built:' . $node['code'] . '@' . $node['parent_code']] as $key)
                $existing[$key] = ($existing[$key] ?? 0) + 1;
        }
        if ($member) foreach ($owned as $node) if ((int)$node['id'] === (int)$member['starter_site_id'] && $node['status'] === 'active') $existing['built:starter-site'] = 1;
        $stock = (new Query())->select(['d.code', 'quantity' => new Expression('SUM(i.item_quantity)')])->from(['i' => 'craft_inventory'])
            ->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')
            ->where(['i.user_id' => $user, 's.owner_user_id' => $user, 's.status' => 'active'])->andWhere(['>', 'i.item_quantity', 0])->groupBy('d.code')->all($this->db);
        foreach ($stock as $item) $existing['crafted:' . $item['code']] = (int)$item['quantity'];
        $existing['acquired:world-stone-pickaxe'] = $existing['crafted:world-stone-pickaxe'] ?? 0;
        $existing['source:gather-log'] = $existing['crafted:classic-log'] ?? 0;
        $existing['source:gather-stone'] = $existing['crafted:classic-stone'] ?? 0;
        $historicalSources = [];
        foreach ((new Query())->select(['w.action_code', 'w.definition_json'])->from(['w' => 'world_source_work'])->innerJoin(['n' => 'world_node'], 'n.id=w.node_id')
            ->where(['w.user_id' => $user, 'w.status' => 'completed', 'n.root_id' => $world])->all($this->db) as $work) {
            $definition = json_decode($work['definition_json'], true);
            $key = 'source:' . $work['action_code'];
            $historicalSources[$key] = ($historicalSources[$key] ?? 0) + array_sum($definition['output'] ?? []);
        }
        foreach ($historicalSources as $key => $quantity) $existing[$key] = max($existing[$key] ?? 0, $quantity);
        $existing['daily_supply_claimed'] = $member ? (int)(new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'gather'])
            ->andWhere(['>=', 'created_at', $member['joined_at']])->count('*', $this->db) : 0;
        foreach (['classic-campfire', 'classic-bench', 'classic-furnace'] as $code) {
            $existing['placed:' . $code] = (int)(new Query())->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
                ->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')->where(['s.owner_user_id' => $user, 's.node_id' => $ids, 's.kind' => 'placement', 'd.code' => $code])->andWhere(['>', 'i.item_quantity', 0])->count('*', $this->db);
        }
        $existing['food-eaten'] = (int)(new Query())->from('world_health_event')->where(['world_id' => $world, 'user_id' => $user])->andWhere(['like', 'kind', 'food:%', false])->count('*', $this->db);
        // Initial open cells have no exploration operation and must not count as explored.
        $existing['map_cell_explored'] = (int)(new Query())->from(['c' => 'world_map_cell'])->innerJoin(['o' => 'game_operation'], 'o.id=c.operation_id')
            ->where(['c.parent_id' => $ids, 'o.user_id' => $user])->andWhere(['like', 'o.type', 'explore'])->count('*', $this->db);
        $existing['bed_dug'] = (int)(new Query())->from('world_bed')->where(['node_id' => $ids])->andWhere(['not', ['dug_at' => null]])->count('*', $this->db);
        $cycles = (new Query())->select('c.id')->from(['c' => 'world_crop_cycle'])->innerJoin(['r' => 'world_crop_revision'], 'r.id=c.crop_revision_id')
            ->innerJoin(['d' => 'craft_item'], 'd.id=r.yield_item_id')->where(['c.owner_user_id' => $user, 'c.bed_id' => $ids, 'd.code' => 'world-carrot']);
        $existing['carrot_sown'] = (int)(clone $cycles)->count('*', $this->db);
        $existing['carrot_harvested'] = (int)(new Query())->from('world_harvest_result')->where(['cycle_id' => $cycles])->count('*', $this->db);
        $actor = (int)(new Query())->select('id')->from('game_actor')->where(['user_id' => $user, 'kind' => 'player'])->scalar($this->db);
        $explorer = (new Query())->select('t.level')->from(['t' => 'actor_profession'])->innerJoin(['p' => 'profession'], 'p.id=t.profession_id')
            ->where(['t.actor_id' => $actor, 'p.code' => 'explorer', 'p.status' => 'published'])->scalar($this->db);
        $existing['explorer-enrolled'] = $explorer === false ? 0 : 1;
        $existing['explorer-level'] = (int)$explorer;
        $lodging = $actor ? (new LodgingAssignments($this->db))->current($actor) : null;
        $existing['lodged'] = $lodging && in_array($lodging['node_id'], array_map('intval', $ids), true) ? 1 : 0;
        $existing['lodged-house'] = HousingBenefits::extractionBonus($this->db, $user, EconomyRuntime::current($this->db)['profile'] ?? []) > 0 ? 1 : 0;
        $existing['paid_work'] = (int)(new Query())->from(['w' => 'world_source_work'])->innerJoin(['n' => 'world_node'], 'n.id=w.node_id')
            ->where(['w.user_id' => $user, 'w.status' => 'completed', 'n.root_id' => $world])->andWhere(['like', 'w.definition_json', '"employment"'])->count('*', $this->db);
        $existing['own-source:mine-stone'] = (int)(new Query())->from(['w' => 'world_source_work'])->innerJoin(['n' => 'world_node'], 'n.id=w.node_id')
            ->where(['w.user_id' => $user, 'w.status' => 'completed', 'w.action_code' => 'mine-stone', 'n.root_id' => $world, 'n.owner_user_id' => $user])->count('*', $this->db);
        foreach ($existing as $code => $quantity) $raw[$code]['quantity'] = max((int)($raw[$code]['quantity'] ?? 0), $quantity);
        return array_map(static function ($fact) { return (int)$fact['quantity']; }, $raw);
    }
}
