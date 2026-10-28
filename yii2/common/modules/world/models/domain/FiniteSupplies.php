<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\WorldTime;
use yii\db\Connection;
use yii\db\Query;

/** A decreasing starter allowance followed by a small once-per-day safety supply. */
final class FiniteSupplies
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function state(int $user): ?array
    {
        $db = $this->db;
        if (!$db->schema->getTableSchema('world_supply_claim')) return null;
        $revision = EconomyRuntime::current($db); if (!$revision) return null;
        $world = EconomyRuntime::world($db); $profile = $revision['profile'];
        $limit = (int)($profile['daily']['claim_limit'] ?? 5); $limit = max(1, min(30, $limit));
        $period = WorldTime::dayWindow($db, time());
        $quantities = $profile['daily']['items'];
        $suppressed = $profile['daily']['suppressed_by'] ?? [
            'classic-log' => ['forest-plot'], 'classic-stone' => ['stone-plot', 'mine'],
            'classic-fibre' => ['meadow-plot'], 'classic-herb' => ['meadow-plot'], 'classic-water' => ['water-source']];
        $built = (new Query())->select('code')->from('world_economy_fact')->where(['world_id' => $world, 'user_id' => $user])->andWhere(['like', 'code', 'built:'])->column($db);
        $built = array_merge($built, array_map(static function ($code) { return 'built:' . $code; },
            (new Query())->select('t.code')->from(['n' => 'world_node'])->innerJoin(['t' => \common\modules\world\models\NodeTemplate::tableName()], 't.id=n.template_id')
                ->where(['n.root_id' => $world, 'n.owner_user_id' => $user, 'n.status' => 'active'])->distinct()->column($db)));
        $built = array_flip($built);
        $claims = (new Query())->from('world_supply_claim')->where(['world_id' => $world, 'user_id' => $user])->all($db);
        $used = []; $today = []; $accepted = [];
        foreach ($claims as $claim) { $id = (int)$claim['item_id']; if (!isset($accepted[$id]) || (int)$claim['ordinal'] > (int)$accepted[$id]['ordinal']) $accepted[$id] = $claim; $used[$id] = max($used[$id] ?? 0, (int)$claim['ordinal']); if ($claim['period_key'] === $period['key']) $today[$id] = true; }
        $rows = (new Query())->from('craft_item')->where(['active' => 1])->orderBy('id')->all($db);
        $items = []; $display = []; $remaining = 0; $future = false;
        $minimum = (int)($profile['daily']['minimum_quantity'] ?? 1);
        $efficiency = (new NightWorkEfficiency($db))->basisPoints($user);
        foreach ($rows as $item) {
            $code = trim($item['code']); $id = (int)$item['id'];
            // Newly introduced gatherable catalogue entries get their own allowance only.
            $base = $quantities[$code] ?? (!isset($profile['items'][$code]) ? (int)$item['gather_quantity'] : 0);
            $base = isset($accepted[$id]) ? (int)$accepted[$id]['base_quantity'] : $base;
            $cap = isset($accepted[$id]) ? (int)$accepted[$id]['grant_limit'] : $limit;
            $initial = isset($accepted[$id]) ? (int)$accepted[$id]['initial_multiplier'] : max($limit, (int)($profile['daily']['initial_multiplier'] ?? 7));
            if ($base < 1) continue;
            $reduced = false; foreach ($suppressed[$code] ?? [] as $source) if (isset($built['built:' . $source])) $reduced = true;
            $left = max(0, $cap - ($used[$id] ?? 0));
            $quantity = $left ? max(1, intdiv($base * $efficiency, 10000)) * ($initial - $cap + $left) : $minimum;
            if ($reduced) $quantity = intdiv($quantity + 1, 2);
            if ($left && isset($accepted[$id])) $quantity = min($quantity, (int)$accepted[$id]['quantity'] - 1);
            $quantity = max($minimum, $quantity);
            if ((!$left && !$minimum) || $quantity < 1) continue;
            $remaining = max($remaining, $left); $future = true;
            $display[] = ['item_id' => $id, 'code' => $code, 'name' => trim($item['name']), 'quantity' => $quantity, 'production_reduction_bps' => $reduced ? 5000 : 0, 'remaining' => $left, 'limit' => $cap, 'minimum_only' => $left === 0];
            if (isset($today[$id])) continue;
            $items[] = ['item_id' => (int)$item['id'], 'code' => $code, 'name' => trim($item['name']),
                'quantity' => $quantity, 'production_reduction_bps' => $reduced ? 5000 : 0, 'base_quantity' => (int)$base, 'grant_limit' => $cap, 'initial_multiplier' => $initial, 'ordinal' => ($used[$id] ?? 0) + 1, 'remaining_after' => max(0, $left - 1), 'previous_quantity' => isset($accepted[$id]) ? (int)$accepted[$id]['quantity'] : null];
        }
        return ['world_id' => $world, 'period_key' => $period['key'], 'period_started_at' => $period['start'], 'items' => $items,
            'gather_available' => (bool)$items, 'gather_available_at' => $future ? $period['end'] : 0,
            'gather_remaining' => $remaining, 'gather_limit' => $limit, 'gather_exhausted' => !$future, 'gather_minimum' => $future && $remaining === 0, 'gather_items' => $display, 'server_time' => time()];
    }
    public function record(int $user, int $event, array $items): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Supply receipt requires command locks.');
        $state = $this->state($user); if (!$state) return;
        foreach ($items as $item) $this->db->createCommand()->insert('world_supply_claim', [
            'world_id' => $state['world_id'], 'user_id' => $user, 'item_id' => $item['item_id'], 'item_code' => $item['code'], 'ordinal' => $item['ordinal'],
            'quantity' => $item['quantity'], 'grant_limit' => $item['grant_limit'], 'base_quantity' => $item['base_quantity'], 'initial_multiplier' => $item['initial_multiplier'], 'period_key' => $state['period_key'], 'event_id' => $event, 'created_at' => time()])->execute();
    }
}
