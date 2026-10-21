<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Two finite server-fact milestones, prepaid from the existing settlement budget. */
final class WorldGoals
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function membership(int $user): ?array
    {
        return (new Query())->from('world_membership')->where(['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user])->one($this->db) ?: null;
    }
    private function facts(int $user): array
    {
        $raw = (new Query())->from('world_economy_fact')->where(['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user])->indexBy('code')->all($this->db);
        $mapping = ['city_joined' => ['city_joined', 1], 'starter_site_active' => ['built:starter-site', 1],
            'explore_3_owned_map_cells' => ['map_cell_explored', 3], 'shelter_built' => ['built:starter-shelter', 1],
            'hammer_crafted' => ['crafted:classic-hammer', 1], 'gather_5_logs' => ['source:gather-log', 5], 'gather_5_stones' => ['source:gather-stone', 5],
            'campfire_placed' => ['placed:classic-campfire', 1], 'garden_built' => ['built:garden', 1], 'shovel_crafted' => ['crafted:world-shovel', 1],
            'bed_dug' => ['bed_dug', 1], 'carrot_sown' => ['carrot_sown', 1], 'daily_supply_claimed' => ['daily_supply_claimed', 1],
            'carrot_harvested' => ['carrot_harvested', 1], 'mine_built' => ['built:mine', 1], 'stone_pickaxe_crafted' => ['crafted:world-stone-pickaxe', 1],
            'stone_mined' => ['source:mine-stone', 1], 'coal_mined' => ['source:gather-coal', 1], 'ore_mined' => ['source:gather-ore', 1],
            'forge_built' => ['built:forge', 1], 'bench_crafted' => ['crafted:classic-bench', 1], 'furnace_crafted' => ['crafted:classic-furnace', 1], 'ingot_crafted' => ['crafted:classic-ingot', 1]];
        // Reconcile players who joined or acquired items before the fact journal was introduced.
        // Use max, never addition: possessions and historical events may describe the same action.
        $world = EconomyRuntime::world($this->db); $existing = [];
        $member = $this->membership($user);
        if ($member) $existing['city_joined'] = 1;
        $owned = (new Query())->select(['n.id', 'n.status', 't.code'])->from(['n' => 'world_node'])->leftJoin(['t' => 'world_template'], 't.id=n.template_id')
            ->where(['n.owner_user_id' => $user, 'n.root_id' => $world])->all($this->db);
        $ids = array_column($owned, 'id');
        foreach ($owned as $node) if ($node['status'] === 'active') $existing['built:' . $node['code']] = ($existing['built:' . $node['code']] ?? 0) + 1;
        if ($member) foreach ($owned as $node) if ((int)$node['id'] === (int)$member['starter_site_id'] && $node['status'] === 'active') $existing['built:starter-site'] = 1;
        $stock = (new Query())->select(['d.code', 'quantity' => new Expression('SUM(i.item_quantity)')])->from(['i' => 'craft_inventory'])
            ->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')
            ->where(['i.user_id' => $user, 's.owner_user_id' => $user, 's.status' => 'active'])->andWhere(['>', 'i.item_quantity', 0])->groupBy('d.code')->all($this->db);
        foreach ($stock as $item) $existing['crafted:' . $item['code']] = (int)$item['quantity'];
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
        $existing['placed:classic-campfire'] = (int)(new Query())->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
            ->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')->where(['s.owner_user_id' => $user, 's.node_id' => $ids, 's.kind' => 'placement', 'd.code' => 'classic-campfire'])->andWhere(['>', 'i.item_quantity', 0])->count('*', $this->db);
        // Initial open cells have no exploration operation and must not count as explored.
        $existing['map_cell_explored'] = (int)(new Query())->from(['c' => 'world_map_cell'])->innerJoin(['o' => 'game_operation'], 'o.id=c.operation_id')
            ->where(['c.parent_id' => $ids, 'o.user_id' => $user])->andWhere(['like', 'o.type', 'explore'])->count('*', $this->db);
        $existing['bed_dug'] = (int)(new Query())->from('world_bed')->where(['node_id' => $ids])->andWhere(['not', ['dug_at' => null]])->count('*', $this->db);
        $cycles = (new Query())->select('c.id')->from(['c' => 'world_crop_cycle'])->innerJoin(['r' => 'world_crop_revision'], 'r.id=c.crop_revision_id')
            ->innerJoin(['d' => 'craft_item'], 'd.id=r.yield_item_id')->where(['c.owner_user_id' => $user, 'c.bed_id' => $ids, 'd.code' => 'world-carrot']);
        $existing['carrot_sown'] = (int)(clone $cycles)->count('*', $this->db);
        $existing['carrot_harvested'] = (int)(new Query())->from('world_harvest_result')->where(['cycle_id' => $cycles])->count('*', $this->db);
        foreach ($existing as $code => $quantity) $raw[$code]['quantity'] = max((int)($raw[$code]['quantity'] ?? 0), $quantity);
        $facts = []; foreach ($mapping as $fact => $source) $facts[$fact] = ['completed' => (int)($raw[$source[0]]['quantity'] ?? 0) >= $source[1],
            'quantity' => (int)($raw[$source[0]]['quantity'] ?? 0), 'required' => $source[1]];
        return $facts;
    }
    private function pool(int $site, string $code): ?array
    {
        return (new Query())->select('p.*')->from(['p' => 'world_goal_pool'])->innerJoin(['c' => 'world_node_closure'], 'c.ancestor_id=p.settlement_id')
            ->where(['c.descendant_id' => $site, 'p.goal_code' => $code])->andWhere(['>', 'p.remaining_claims', 0])->orderBy(['p.id' => SORT_ASC])->one($this->db) ?: null;
    }
    private function actionLinks(int $user, int $site): array
    {
        $nodes = (new Query())->select(['n.id', 't.code'])->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')
            ->where(['n.owner_user_id' => $user, 'n.root_id' => EconomyRuntime::world($this->db), 'n.status' => 'active'])->orderBy('n.id')->all($this->db);
        $byCode = []; foreach ($nodes as $node) if (!isset($byCode[$node['code']]) && NodeAvailability::activePath($this->db, (int)$node['id'])) $byCode[$node['code']] = (int)$node['id'];
        $map = '/world/nodes/' . $site; $craft = '/world/workspace/' . $site;
        $at = static function ($code) use ($byCode, $map) { return isset($byCode[$code]) ? '/world/nodes/' . $byCode[$code] : $map; };
        return ['city_joined' => '/world', 'starter_site_active' => $map, 'explore_3_owned_map_cells' => $at('forest-plot'),
            'shelter_built' => $map, 'hammer_crafted' => $craft, 'gather_5_logs' => $at('forest-plot'), 'gather_5_stones' => $at('stone-plot'),
            'campfire_placed' => $at('campfire-place'), 'garden_built' => $map, 'shovel_crafted' => $craft, 'bed_dug' => $at('garden-bed'), 'carrot_sown' => $at('garden-bed'),
            'daily_supply_claimed' => $map, 'carrot_harvested' => $at('garden-bed'), 'mine_built' => $map, 'stone_pickaxe_crafted' => $craft,
            'stone_mined' => $at('mine'), 'coal_mined' => $at('mine'), 'ore_mined' => $at('mine'), 'forge_built' => $map,
            'bench_crafted' => $craft, 'furnace_crafted' => $craft, 'ingot_crafted' => $craft];
    }
    public function state(int $user): array
    {
        $revision = EconomyRuntime::current($this->db); $member = $revision ? $this->membership($user) : null;
        $goals = []; $facts = $revision ? $this->facts($user) : [];
        $claims = $revision ? (new Query())->from('world_goal_claim')->where(['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user])->indexBy('goal_code')->all($this->db) : [];
        $links = $member ? $this->actionLinks($user, (int)$member['starter_site_id']) : [];
        if ($revision) foreach ($revision['profile']['goals'] as $code => $definition) {
            $pool = $member ? $this->pool((int)$member['starter_site_id'], $code) : null;
            $accepted = $pool ? json_decode($pool['definition_json'], true) : $definition;
            $steps = []; foreach ($accepted['facts'] as $fact) $steps[] = ['code' => $fact, 'action_href' => $links[$fact] ?? '/world'] + ($facts[$fact] ?? ['completed' => false, 'quantity' => 0, 'required' => 1]);
            if (isset($claims[$code])) foreach ($steps as &$step) { $step['completed'] = true; $step['quantity'] = max($step['quantity'], $step['required']); }
            unset($step);
            $ready = $member && !array_filter($steps, static function ($step) { return !$step['completed']; });
            if ($code === 'day-2' && !isset($claims['day-1'])) $ready = false;
            $goals[] = ['code' => $code, 'steps' => $steps, 'claimed' => isset($claims[$code]), 'funded' => (bool)$pool,
                'can_claim' => (bool)$ready && $pool !== null && !isset($claims[$code]), 'credits_cr' => $accepted['credits_cr'],
                'experience' => $accepted['experience'], 'items' => $accepted['items'], 'destination_node_id' => $member ? (int)$member['starter_site_id'] : null];
        }
        return ['goals' => $goals, 'profile_version' => $revision ? $revision['version'] : null, 'server_time' => time()];
    }
    public function fund(int $settlement, string $code, int $count, int $author, string $operation): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Goal funding needs command locks.');
        $revision = EconomyRuntime::current($this->db);
        $definition = $revision['profile']['goals'][$code] ?? null;
        if (!$definition || $count < 1 || $count > 1000) throw new GameError('INVALID_GOAL_POOL', 'Выберите цель и число участников от 1 до 1000.', 422);
        $node = (new Query())->from('world_node')->where(['id' => $settlement, 'hierarchy_level' => 3, 'status' => 'active', 'root_id' => EconomyRuntime::world($this->db)])->one($this->db);
        if (!$node) throw new GameError('INVALID_SETTLEMENT', 'Выберите поселение действующего мира.', 422);
        $accounts = (new EconomyHierarchy($this->db))->provision($settlement, $operation);
        $amount = Money::parse($definition['credits_cr'])->multiply($count);
        $hold = (new BudgetSpending($this->db))->reserve((int)$accounts['budget']['id'], $amount, 'Стартовая цель: ' . $code, $operation);
        $this->db->createCommand()->insert('world_goal_pool', ['world_id' => EconomyRuntime::world($this->db), 'settlement_id' => $settlement,
            'goal_code' => $code, 'profile_id' => $revision['id'], 'definition_json' => CanonicalJson::encode($definition), 'commitment_id' => $hold,
            'remaining_claims' => $count, 'created_by' => $author, 'operation_id' => $operation, 'created_at' => time()])->execute();
        return ['pool_id' => (int)$this->db->getLastInsertID(), 'reserved_cr' => $amount->decimal()];
    }
    public function claim(int $user, string $code, string $operation): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Goal claim needs command locks.');
        $selected = null;
        foreach ($this->state($user)['goals'] as $goal) if ($goal['code'] === $code) $selected = $goal;
        if (!$selected || !$selected['can_claim']) throw new GameError('GOAL_NOT_READY', 'Выполните шаги цели; награда должна быть обеспечена бюджетом и ещё не получена.', 409);
        $site = $selected['destination_node_id']; $pool = $this->pool($site, $code); $definition = json_decode($pool['definition_json'], true);
        $store = new CraftStorage($this->db); $store->operationId = $operation;
        foreach ($definition['items'] as $itemCode => $quantity) {
            $item = (new Query())->from('craft_item')->where(['code' => $itemCode, 'active' => 1])->one($this->db);
            if (!$item) throw new GameError('GOAL_ITEM_UNAVAILABLE', 'Предмет награды временно недоступен.', 409);
            $store->move($user, $item, $quantity);
        }
        (new BudgetSpending($this->db))->pay((int)$pool['commitment_id'], $site, Money::parse($definition['credits_cr']), $operation, 'starter_goal');
        $category = (int)(new Query())->select('id')->from('craft_category')->orderBy('id')->scalar($this->db);
        $where = ['user_id' => $user, 'category_id' => $category]; $skill = (new Query())->from('craft_skill')->where($where)->one($this->db);
        if ($skill) $this->db->createCommand()->update('craft_skill', ['experience' => min(9900, (int)$skill['experience'] + $definition['experience'])], $where)->execute();
        else $this->db->createCommand()->insert('craft_skill', $where + ['experience' => $definition['experience']])->execute();
        $this->db->createCommand()->insert('world_goal_claim', ['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user, 'goal_code' => $code,
            'pool_id' => $pool['id'], 'operation_id' => $operation, 'earned_cr' => $definition['credits_cr'], 'created_at' => time()])->execute();
        $this->db->createCommand()->update('world_goal_pool', ['remaining_claims' => new Expression('remaining_claims-1')], ['id' => $pool['id']])->execute();
        return ['goals' => $this->state($user), 'changed_node_ids' => [$site], 'earned_cr' => $definition['credits_cr']];
    }
}
