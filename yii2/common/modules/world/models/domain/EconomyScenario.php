<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\EquipmentExposure;
use common\modules\world\modules\economy\value\Money;

/** Deterministic offline route audit, not an alternate game command executor. */
final class EconomyScenario
{
    private $profile;
    private $config;
    private $state;
    private $initialMoney;

    public static function load(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/data/economy/closed-test-scenarios-v1.json'), true, 64, JSON_THROW_ON_ERROR);
    }
    public static function routeSteps(array $config, string $scenario, bool $finite = false): array
    {
        $common = $config['common_steps'];
        if (($config['initial_open_cells'] ?? 9) === 1) {
            $cells = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];
            array_splice($common, 1, 0, [['action' => 'explore', 'code' => 'starter-site', 'cells' => $cells], ['action' => 'buy', 'code' => 'starter-site', 'cells' => $cells]]);
        }
        if ($finite) {
            $daily = null; foreach ($common as $step) if ($step['action'] === 'daily') { $daily = $step; break; }
            if ($daily) { $common = array_values(array_filter($common, static function ($step) { return $step['action'] !== 'daily'; })); array_splice($common, 1, 0, [$daily]); }
        }
        return array_merge($common, $config['scenarios'][$scenario]);
    }

    private static function need(bool $condition, string $code, string $detail = ''): void
    {
        if (!$condition) throw new \DomainException($code . '|' . $detail);
    }
    private static function money(string $value): Money { return Money::parse($value); }
    private function day(): string { return (new \DateTimeImmutable('@' . $this->state['now']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'); }
    private function available(string $node): Money { return $this->state['budgets'][$node]->subtract($this->state['tax_reserved'][$node]); }
    private function efficiency(): int
    {
        return $this->state['night_enabled'] ? NightCalendar::efficiency($this->config['night_rules'], $this->state['health']['severity']) : 10000;
    }
    private function settleNights(): void
    {
        if (!$this->state['night_enabled']) return;
        $policy = $this->config['night_rules'];
        for ($i = 0; $i < 32; $i++) {
            $night = NightCalendar::bounds($policy, $this->state['health']['next_sequence']);
            if ($night['ended_at'] > $this->state['now']) return;
            $protected = false;
            foreach ($this->state['lodging'] as $interval) {
                if ($interval['started_at'] <= $night['started_at'] && ($interval['ended_at'] === null || $interval['ended_at'] >= $night['ended_at'])) $protected = true;
            }
            $next = NightCalendar::advance($policy, $this->state['health'], $protected, $night['ended_at']);
            $this->state['night_history'][] = $night + ['protected' => $protected, 'severity_before' => $this->state['health']['severity'], 'severity_after' => $next['severity']];
            $this->state['health'] = $next + ['next_sequence' => $night['sequence'] + 1];
        }
        self::need(NightCalendar::bounds($policy, $this->state['health']['next_sequence'])['ended_at'] > $this->state['now'], 'NIGHT_CATCHING_UP');
    }
    private function flow(string $from, string $to, Money $amount, string $kind): void
    {
        if (!$amount->isZero()) $this->state['flows'][] = ['step' => $this->state['step'], 'from' => $from, 'to' => $to, 'amount_cr' => $amount->decimal(), 'kind' => $kind];
    }
    private function quantity(array $step): int
    {
        $quantity = $step['quantity'] ?? 1;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 10000) throw new \InvalidArgumentException('Expected a bounded positive integer quantity.');
        return $quantity;
    }
    private function stock(array $items, int $factor): void
    {
        foreach ($items as $code => $quantity) {
            $next = ($this->state['stock'][$code] ?? 0) + $quantity * $factor;
            self::need($next >= 0, 'MATERIAL_REQUIRED', $code);
            $this->state['stock'][$code] = $next;
        }
    }
    private function capacity(): void
    {
        $slots = 0;
        foreach ($this->state['stock'] as $code => $quantity) $slots += intdiv($quantity + $this->profile['items'][$code]['stack_size'] - 1, $this->profile['items'][$code]['stack_size']);
        self::need($slots <= $this->profile['storage']['backpack_slots'], 'BACKPACK_FULL', (string)$slots);
        $this->state['peak_slots'] = max($this->state['peak_slots'], $slots);
    }
    private function placementLimit(string $node): int
    {
        $initial = $node === 'starter-site' ? $this->profile['map']['starter_open_cells'] : $this->profile['map']['other_nodes_initial_open'];
        return $initial + count($this->state['paid_cells'][$node] ?? []);
    }
    private function mapCells(array $step): array
    {
        self::need(isset($this->state['objects'][$step['code']]), 'MAP_UNAVAILABLE');
        $cells = $step['cells'] ?? null;
        if (!is_array($cells) || count($cells) < 1 || count($cells) > 100 || !is_bool($step['use_elixir'] ?? false)) throw new \InvalidArgumentException('Invalid scenario map input.');
        $result = []; $bounds = $this->profile['map']['bounds'];
        foreach ($cells as $point) {
            if (!is_array($point) || count($point) !== 2 || !is_int($point[0] ?? null) || !is_int($point[1] ?? null)) throw new \InvalidArgumentException('Expected integer map coordinates.');
            self::need($point[0] >= $bounds[0] && $point[0] <= $bounds[1] && $point[1] >= $bounds[0] && $point[1] <= $bounds[1], 'MAP_OUT_OF_BOUNDS');
            $key = $step['code'] . ':' . $point[0] . ':' . $point[1];
            self::need(!isset($result[$key]), 'MAP_DUPLICATE_CELL');
            $result[$key] = ['x' => $point[0], 'y' => $point[1]];
        }
        uasort($result, static function ($a, $b) { return [$a['y'], $a['x']] <=> [$b['y'], $b['x']]; });
        return $result;
    }
    private function spend(string $node, Money $amount): void
    {
        $gap = $amount->compare($this->available($node)) > 0 ? $amount->subtract($this->available($node)) : self::money('0');
        self::need($this->state['wallet']->compare($gap) >= 0, 'INSUFFICIENT_FUNDS', 'need ' . $amount->decimal() . ', available ' . $this->available($node)->add($this->state['wallet'])->decimal() . ' at ' . $node);
        $this->state['wallet'] = $this->state['wallet']->subtract($gap);
        $this->state['budgets'][$node] = $this->state['budgets'][$node]->add($gap)->subtract($amount);
        $this->state['municipal'] = $this->state['municipal']->add($amount);
        $this->flow('wallet', $node . ':budget', $gap, 'personal_contribution');
        $this->flow($node . ':budget', 'settlement:budget', $amount, 'candidate_mandatory_fee');
    }
    private function receive(string $node, Money $amount, string $kind): void
    {
        self::need($this->state['municipal_reserved']->compare($amount) >= 0, 'MUNICIPAL_RESERVE_EXHAUSTED');
        $this->state['municipal_reserved'] = $this->state['municipal_reserved']->subtract($amount);
        $this->state['municipal'] = $this->state['municipal']->subtract($amount);
        $this->state['receipts'][$node][] = ['gross_cr' => $amount->decimal(), 'received_at' => $this->state['now']];
        $this->flow('settlement:budget', $node . ':treasury', $amount, $kind);
    }
    private function tool(string $code, int $base): void
    {
        $units = $this->state['tools'][$code] ?? [];
        self::need((bool)$units, 'TOOL_REQUIRED', $code);
        $best = array_search(max($units), $units, true);
        $wear = EquipmentExposure::workCost('carried', $base);
        self::need($units[$best] >= max(1, $wear), 'TOOL_BROKEN', $code);
        $this->state['tools'][$code][$best] -= $wear;
    }
    private function stationDurability(array $station): int
    {
        return max(0, $station['work_remaining'] - intdiv(($this->state['now'] - $station['at']) * EquipmentExposure::dailyRates()[$station['exposure']], 86400));
    }
    private function station(string $code): void
    {
        self::need(isset($this->state['stations'][$code]), 'STATION_REQUIRED', $code);
        $station = $this->state['stations'][$code];
        $wear = EquipmentExposure::workCost($station['exposure'], $this->profile['operating_costs']['craft_base_work_wear'], 1, true);
        self::need($this->stationDurability($station) >= max(1, $wear), 'STATION_BROKEN', $code);
        $this->state['stations'][$code]['work_remaining'] -= $wear;
    }
    private function facts(): array
    {
        $facts = $this->state['facts'];
        $facts['gather_5_logs'] = ($this->state['gathered']['gather-log'] ?? 0) >= 5;
        $facts['gather_5_stones'] = ($this->state['gathered']['gather-stone'] ?? 0) >= 5;
        $facts['explore_3_owned_map_cells'] = count($this->state['explored']) >= 3;
        $facts['daily_supply_claimed'] = isset($this->state['daily'][$this->day()]);
        return $facts;
    }

    private function apply(array $step): void
    {
        $action = $step['action']; $code = $step['code'] ?? ''; $quantity = $this->quantity($step); $day = $this->day();
        if ($action === 'build') {
            self::need(isset($this->profile['objects'][$code]), 'UNKNOWN_OBJECT', $code);
            $row = $this->profile['objects'][$code]; $parent = $step['parent'];
            self::need(!isset($this->state['objects'][$code]) && ($parent === 'settlement' || isset($this->state['objects'][$parent])), 'OBJECT_UNAVAILABLE', $code);
            foreach ($row['capability_requirements'] ?? [] as $required) self::need(isset($this->state['objects'][$required]), 'OBJECT_CAPABILITY_REQUIRED', $required);
            if ($parent !== 'settlement') {
                $limit = $this->placementLimit($parent);
                self::need(($this->state['placements'][$parent] ?? 0) < $limit, 'PLACEMENT_FULL', $parent);
                $this->spend($parent, self::money($row['price_cr']));
                $this->state['placements'][$parent] = ($this->state['placements'][$parent] ?? 0) + 1;
            } else self::need($code === 'starter-site' && self::money($row['price_cr'])->isZero(), 'INVALID_STARTER_SITE');
            $this->stock($row['materials'], -1); $this->state['now'] += $row['work_seconds'];
            $this->state['objects'][$code] = $parent;
            $this->state['budgets'][$code] = self::money('0'); $this->state['tax_reserved'][$code] = self::money('0'); $this->state['tax_carry'][$code] = 0;
            if ($code === 'starter-site' && ($this->profile['starter']['funding'] ?? '') === 'parent_budget') {
                $amount = self::money($this->profile['starter']['credits_cr']);
                self::need($this->state['municipal']->subtract($this->state['municipal_reserved'])->compare($amount) >= 0, 'STARTER_FUNDING_PENDING');
                $this->state['municipal'] = $this->state['municipal']->subtract($amount); $this->state['budgets'][$code] = $amount;
            }
            foreach (['starter-site' => 'starter_site_active', 'starter-shelter' => 'shelter_built', 'garden' => 'garden_built', 'mine' => 'mine_built', 'forge' => 'forge_built'] as $object => $fact) if ($code === $object) $this->state['facts'][$fact] = true;
        } elseif ($action === 'craft') {
            self::need(isset($this->profile['recipes'][$code]), 'UNKNOWN_RECIPE', $code);
            $row = $this->profile['recipes'][$code];
            self::need($row['min_level'] === 1, 'SKILL_NOT_MODELLED', $code);
            foreach ($row['requires'] as $required) self::need(isset($this->state['crafted'][$required]), 'RECIPE_NOT_LEARNED', $required);
            if ($row['context'] !== null) self::need(isset($this->state['objects'][$row['context']]), 'CRAFT_CONTEXT_REQUIRED', $row['context']);
            $recent = array_filter($this->state['craft_times'], function ($at) { return $at > $this->state['now'] - 3600; });
            self::need(count($recent) + $quantity <= $this->profile['limits']['crafting_hourly']['maximum'], 'CRAFT_HOURLY_LIMIT');
            $fee = self::money($row['fee_cr'])->multiply($quantity);
            if (($this->profile['starter']['funding'] ?? '') === 'parent_budget') {
                self::need($this->available('starter-site')->compare($fee) >= 0, 'INSUFFICIENT_CRAFT_BUDGET');
                $this->state['budgets']['starter-site'] = $this->state['budgets']['starter-site']->subtract($fee);
                $this->flow('starter-site:budget', 'craft_fee_sink', $fee, 'existing_craft_debit');
            } else {
                self::need($this->state['wallet']->compare($fee) >= 0, 'INSUFFICIENT_CRAFT_WALLET');
                $this->state['wallet'] = $this->state['wallet']->subtract($fee); $this->flow('wallet', 'craft_fee_sink', $fee, 'existing_craft_debit');
            }
            $this->state['craft_fee_sink'] = $this->state['craft_fee_sink']->add($fee);
            for ($i = 0; $i < $quantity; $i++) {
                foreach ($row['tools'] as $tool) if ($tool !== $row['station']) $this->tool($tool, $this->profile['operating_costs']['craft_base_work_wear']);
                if ($row['station'] !== null) $this->station($row['station']);
                $this->stock($row['ingredients'], -1); $this->stock([$row['output'] => $row['quantity']], 1);
                if (in_array($this->profile['items'][$row['output']]['kind'], ['tool', 'station'], true)) for ($unit = 0; $unit < $row['quantity']; $unit++) $this->state['tools'][$row['output']][] = $this->profile['operating_costs']['equipment_max_durability'];
                $this->state['craft_times'][] = $this->state['now'];
                $this->capacity();
            }
            $this->state['crafted'][$code] = ($this->state['crafted'][$code] ?? 0) + $quantity;
            foreach (['classic-hammer' => 'hammer_crafted', 'world-shovel' => 'shovel_crafted', 'world-stone-pickaxe' => 'stone_pickaxe_crafted', 'classic-bench' => 'bench_crafted', 'classic-furnace' => 'furnace_crafted', 'classic-ingot' => 'ingot_crafted'] as $recipe => $fact) if ($code === $recipe) $this->state['facts'][$fact] = true;
        } elseif ($action === 'source') {
            self::need(isset($this->profile['actions'][$code]), 'UNKNOWN_SOURCE', $code);
            $row = $this->profile['actions'][$code];
            self::need($row['quota'] === 'extraction_daily' && isset($this->state['objects'][$row['node']]), 'SOURCE_UNAVAILABLE', $code);
            self::need(($this->state['extraction'][$day] ?? 0) + $quantity <= $this->profile['limits']['extraction_daily']['maximum'], 'EXTRACTION_DAILY_LIMIT');
            self::need(($this->state['action_counts'][$day][$code] ?? 0) + $quantity <= ($row['daily_limit'] ?? 10000), 'SOURCE_DAILY_LIMIT', $code);
            $this->spend('starter-site', self::money($row['fee_cr'])->multiply($quantity));
            for ($i = 0; $i < $quantity; $i++) {
                foreach ($row['tools'] as $tool) $this->tool($tool, $this->profile['operating_costs']['source_tool_wear_per_batch']);
                $this->stock($row['ingredients'], -1); $this->state['now'] += $row['work_seconds'] + $row['cooldown_seconds'];
                self::need($day === $this->day(), 'SOURCE_BATCH_CROSSES_DAY');
                $this->stock($row['output'], 1); $this->capacity();
                $this->state['gathered'][$code] = ($this->state['gathered'][$code] ?? 0) + array_sum($row['output']);
            }
            $this->state['extraction'][$day] = ($this->state['extraction'][$day] ?? 0) + $quantity;
            $this->state['action_counts'][$day][$code] = ($this->state['action_counts'][$day][$code] ?? 0) + $quantity;
            foreach (['mine-stone' => 'stone_mined', 'gather-coal' => 'coal_mined', 'gather-ore' => 'ore_mined'] as $source => $fact) if ($code === $source) $this->state['facts'][$fact] = true;
        } elseif ($action === 'place') {
            $node = $step['node'];
            self::need(isset($this->state['objects'][$node]) && ($this->state['placements'][$node] ?? 0) < $this->placementLimit($node), 'PLACEMENT_FULL', $node);
            $this->stock([$code => 1], -1); $this->state['placements'][$node] = ($this->state['placements'][$node] ?? 0) + 1;
            if ($this->profile['items'][$code]['kind'] === 'station') {
                self::need(!isset($this->state['stations'][$code]), 'STATION_ALREADY_PLACED');
                $durability = array_pop($this->state['tools'][$code]);
                $this->state['stations'][$code] = ['node' => $node, 'at' => $this->state['now'], 'work_remaining' => $durability, 'exposure' => 'outdoor'];
            }
            if ($code === 'classic-campfire') $this->state['facts']['campfire_placed'] = true;
            if ($code === 'classic-bed') {
                self::need(in_array($this->profile['objects'][$node]['exposure_class'] ?? 'outdoor', ['covered', 'indoor'], true), 'BED_REQUIRES_COVER');
                $this->state['beds'][$node] = true;
            }
        } elseif ($action === 'lodge') {
            self::need(isset($this->state['beds'][$code]), 'BED_REQUIRED', $code);
            foreach ($this->state['lodging'] as $interval) self::need($interval['ended_at'] !== null, 'LODGING_ALREADY_ASSIGNED');
            $this->state['lodging'][] = ['node' => $code, 'started_at' => $this->state['now'], 'ended_at' => null];
        } elseif ($action === 'leave') {
            $active = null;
            foreach ($this->state['lodging'] as $index => $interval) if ($interval['ended_at'] === null) $active = $index;
            self::need($active !== null, 'LODGING_NOT_ASSIGNED');
            $this->state['lodging'][$active]['ended_at'] = $this->state['now'];
        } elseif ($action === 'explore') {
            $cells = $this->mapCells($step); $elixirs = 0;
            foreach ($cells as $key => $point) {
                $level = MapExploration::requiredLevel($point['x'], $point['y']);
                self::need($level === 1 || ($step['use_elixir'] ?? false), 'EXPLORER_LEVEL_REQUIRED');
                if ($level > 1) $elixirs++;
                $initial = $code === 'starter-site' && $this->profile['map']['starter_open_cells'] === 9 ? max(abs($point['x']), abs($point['y'])) <= 1 : $point === ['x' => 0, 'y' => 0];
                self::need(!$initial && !isset($this->state['explored'][$key]), 'MAP_CELL_EXPLORED');
                $this->state['explored'][$key] = true;
            }
            if ($step['use_elixir'] ?? false) $this->stock([ExplorationCatalog::ELIXIR => count($cells) === 1 ? 1 : $elixirs], -1);
        } elseif ($action === 'buy') {
            $cells = $this->mapCells($step);
            self::need(!($step['use_elixir'] ?? false), 'INVALID_MAP_ACTION');
            foreach ($cells as $key => $point) self::need(isset($this->state['explored'][$key]) && !isset($this->state['paid_cells'][$code][$key]), 'MAP_CELL_NOT_DISCOVERED');
            $map = $this->profile['map'];
            $quote = WorldMapCells::purchasePricing(['base_price' => $map['base_price_cr'], 'bulk_minimum' => $map['bulk_minimum'], 'discount_bps' => $map['discount_bps']], array_values($cells));
            $this->spend($code, self::money($quote['total']));
            foreach (array_keys($cells) as $i => $key) $this->state['paid_cells'][$code][$key] = $quote['unit_prices'][$i];
        } elseif ($action === 'daily') {
            self::need(!isset($this->state['daily'][$day]), 'DAILY_ALREADY_CLAIMED');
            $grant = [];
            foreach ($this->profile['daily']['items'] as $item => $amount) {
                if ($this->config['finite_supplies']) {
                    $sources = $this->profile['daily']['suppressed_by'][$item] ?? [];
                    if (array_intersect($sources, array_keys($this->state['objects']))) continue;
                    $used = $this->state['supply_claims'][$item] ?? 0;
                    if ($used >= $this->profile['daily']['claim_limit']) continue;
                    $grant[$item] = max(1, intdiv($amount * $this->efficiency(), 10000)) * ($this->profile['daily']['initial_multiplier'] - $used);
                    $this->state['supply_claims'][$item] = $used + 1;
                } else $grant[$item] = max(1, intdiv($amount * $this->efficiency(), 10000));
            }
            if (!$grant) return; // The UI hides exhausted supplies; no command or quota is consumed.
            $this->stock($grant, 1); $this->state['daily'][$day] = true;
        } elseif ($action === 'grant') {
            // Direct owned children only; retain explicit movement and reserved parent funds.
            $source = $step['source']; $target = $step['destination'];
            self::need($source === 'starter-site' && in_array($target, ['garden', 'stone-plot'], true) && ($this->state['objects'][$target] ?? null) === $source, 'GRANT_RELATION_REQUIRED');
            if (!is_string($step['amount_cr'] ?? null)) throw new \InvalidArgumentException('Expected an exact budget grant.');
            $amount = self::money($step['amount_cr']);
            self::need(!$amount->isZero() && !$amount->isNegative(), 'INVALID_GRANT_AMOUNT');
            self::need($this->available($source)->compare($amount) >= 0, 'INSUFFICIENT_GRANT_BUDGET');
            $this->state['budgets'][$source] = $this->state['budgets'][$source]->subtract($amount);
            $this->state['budgets'][$target] = $this->state['budgets'][$target]->add($amount);
            $this->flow($source . ':budget', $target . ':budget', $amount, 'existing_targeted_budget_grant');
        } elseif ($action === 'day') {
            $at = $this->config['start'] + ($quantity - 1) * 86400;
            self::need($at >= $this->state['now'] && $quantity <= 7, 'INVALID_DAY_ADVANCE'); $this->state['now'] = $at;
        } elseif ($action === 'sow') {
            self::need(isset($this->state['objects']['garden-bed']) && $this->state['crop'] === null, 'BED_UNAVAILABLE');
            $crop = $this->profile['crop']; $this->tool('world-shovel', $this->profile['operating_costs']['crop_dig_tool_wear_per_cycle']);
            $this->spend('garden', self::money($crop['dig_fee_cr'])->add(self::money($crop['sow_fee_cr'])));
            $this->stock([$crop['seed'] => $crop['seed_quantity'], $crop['water'] => $crop['water_quantity_per_action']], -1);
            // Conservative labour allowance within the 180-second aggregate, not a live craft timer.
            $this->state['now'] += 120;
            $this->state['crop'] = ['ready_at' => $this->state['now'] + $crop['grow_seconds'], 'water_due_at' => $this->state['now'] + $crop['water_interval_seconds'], 'water_missed' => 0];
            $this->state['facts']['bed_dug'] = true; $this->state['facts']['carrot_sown'] = true;
        } elseif ($action === 'cancel_crop') {
            self::need($this->state['crop'] !== null, 'CROP_REQUIRED');
            $this->state['cancelled_crops'][] = CultivationClock::project($this->state['crop'], $this->profile['crop'], $this->state['now']);
            $this->state['crop'] = null;
        } elseif ($action === 'water' || $action === 'harvest') {
            self::need($this->state['crop'] !== null, 'CROP_REQUIRED'); $crop = $this->profile['crop'];
            if ($action === 'water' && $this->state['crop']['water_due_at'] !== null) $this->state['now'] = max($this->state['now'], $this->state['crop']['water_due_at']);
            $clock = CultivationClock::project($this->state['crop'], $crop, $this->state['now']);
            if ($action === 'water') {
                self::need($clock['can_water'], 'WATER_NOT_DUE');
                $this->spend('garden', self::money($crop['water_fee_cr'])); $this->stock([$crop['water'] => 1], -1);
                $this->state['now'] += 60; $this->state['crop']['water_due_at'] = null;
            } else {
                self::need($clock['state'] === 'ripe', 'CROP_NOT_READY_OR_EXPIRED');
                $quantity = max(1, intdiv($crop['yield_quantity'] * $this->efficiency() * $clock['yield_factor_bps'], 100000000));
                $this->spend('garden', self::money($crop['harvest_fee_cr'])); $this->stock([$crop['yield'] => $quantity], 1);
                $this->state['crop'] = null; $this->state['facts']['carrot_harvested'] = true;
            }
        } elseif ($action === 'goal') {
            $goal = $this->profile['goals'][$code]; $facts = $this->facts();
            self::need(!isset($this->state['goals'][$code]), 'GOAL_ALREADY_CLAIMED');
            foreach ($goal['facts'] as $fact) self::need(!empty($facts[$fact]), 'GOAL_FACT_REQUIRED', $fact);
            $this->receive('starter-site', self::money($goal['credits_cr']), 'funded_goal:' . $code); $this->stock($goal['items'], 1); $this->state['goals'][$code] = true;
        } elseif ($action === 'deliver') {
            $node = $step['destination'];
            self::need(isset($this->state['orders'][$code]) && isset($this->state['objects'][$node]) && in_array($node, ['starter-site', 'garden'], true) && ($node !== 'garden' || $code === $this->profile['crop']['yield']), 'ORDER_UNAVAILABLE');
            self::need($this->state['now'] < $this->config['start'] + $this->config['order_lifetime_seconds'], 'ORDER_EXPIRED');
            self::need($quantity <= $this->state['orders'][$code] && $quantity <= $this->profile['limits']['sales_daily']['same_item_per_operation'], 'ORDER_QUANTITY_LIMIT');
            $amount = self::money($this->config['orders'][$code]['unit_price_cr'])->multiply($quantity);
            $sales = $this->state['sales'][$day] ?? ['operations' => 0, 'gross_cr' => '0.0000'];
            self::need($sales['operations'] < $this->profile['limits']['sales_daily']['operations'] && self::money($sales['gross_cr'])->add($amount)->compare(self::money($this->profile['limits']['sales_daily']['gross_cr'])) <= 0, 'SALES_DAILY_LIMIT');
            $this->stock([$code => $quantity], -1); $this->receive($node, $amount, 'funded_order:' . $code); $this->state['orders'][$code] -= $quantity;
            $this->state['sales'][$day] = ['operations' => $sales['operations'] + 1, 'gross_cr' => self::money($sales['gross_cr'])->add($amount)->decimal()];
        } elseif ($action === 'collect') {
            $sum = self::money('0');
            foreach ($this->state['receipts'][$code] ?? [] as $receipt) {
                $policy = $this->profile['operating_costs']['treasury_example']; $policy['gross_cr'] = $receipt['gross_cr']; $policy['rate_bps'] = 0;
                $forecast = EconomyOperatingCosts::treasury($policy, $this->state['now'] - $receipt['received_at']);
                $sum = $sum->add(self::money($forecast['collected_to_budget_cr'])); $this->state['loss_sink'] = $this->state['loss_sink']->add(self::money($forecast['lost_cr']));
                $this->flow($code . ':treasury', 'loss_sink', self::money($forecast['lost_cr']), 'treasury_loss');
            }
            self::need(!$sum->isZero(), 'TREASURY_EMPTY');
            $tax = $sum->portion($this->profile['operating_costs']['treasury_example']['rate_bps'], $this->state['tax_carry'][$code]);
            $this->state['tax_carry'][$code] = $tax['carry']; $this->state['tax_reserved'][$code] = $this->state['tax_reserved'][$code]->add($tax['amount']);
            $this->state['budgets'][$code] = $this->state['budgets'][$code]->add($sum); $this->state['receipts'][$code] = [];
            $this->flow($code . ':treasury', $code . ':budget', $sum, 'collection_with_parent_reserve');
        } elseif ($action === 'checkpoint') {
            $this->state['checkpoints'][$code] = $this->summary();
        } else throw new \InvalidArgumentException('Unknown economy scenario action: ' . $action);
        $this->settleNights();
        $this->capacity();
    }

    private function summary(): array
    {
        $total = $this->state['wallet']->add($this->state['municipal'])->add($this->state['loss_sink'])->add($this->state['craft_fee_sink']); $budgets = []; $treasuries = [];
        foreach ($this->state['budgets'] as $node => $amount) {
            $total = $total->add($amount); $budgets[$node] = ['amount_cr' => $amount->decimal(), 'reserved_parent_cr' => $this->state['tax_reserved'][$node]->decimal(), 'available_cr' => $this->available($node)->decimal()];
        }
        foreach ($this->state['receipts'] as $node => $receipts) {
            $amount = self::money('0'); foreach ($receipts as $receipt) $amount = $amount->add(self::money($receipt['gross_cr']));
            $total = $total->add($amount); $treasuries[$node] = $amount->decimal();
        }
        $stations = []; foreach ($this->state['stations'] as $code => $station) $stations[$code] = $this->stationDurability($station);
        return ['at' => (new \DateTimeImmutable('@' . $this->state['now']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('c'),
            'wallet_cr' => $this->state['wallet']->decimal(), 'budgets' => $budgets, 'treasuries_cr' => $treasuries,
            'municipal_budget_cr' => $this->state['municipal']->decimal(), 'municipal_reserved_cr' => $this->state['municipal_reserved']->decimal(),
            'loss_sink_cr' => $this->state['loss_sink']->decimal(), 'craft_fee_sink_cr' => $this->state['craft_fee_sink']->decimal(), 'money_total_with_sinks_cr' => $total->decimal(), 'money_conserved' => $total->compare($this->initialMoney) === 0,
            'stock' => array_filter($this->state['stock']), 'objects' => $this->state['objects'], 'tools' => $this->state['tools'], 'stations' => $stations,
            'extraction' => $this->state['extraction'], 'sales' => $this->state['sales'], 'crafted_batches' => $this->state['crafted'],
            'remaining_orders' => $this->state['orders'], 'claimed_goals' => array_keys($this->state['goals']), 'explored_cells' => count($this->state['explored']), 'peak_backpack_slots' => $this->state['peak_slots'],
            'night_policy_enabled' => $this->state['night_enabled'], 'night_health' => $this->state['night_enabled'] ? $this->state['health'] + ['work_efficiency_bps' => $this->efficiency()] : null,
            'lodging_intervals' => $this->state['lodging'], 'paid_map_cells' => $this->state['paid_cells'], 'placements' => $this->state['placements'],
            'claimed_daily_dates' => array_keys($this->state['daily']), 'cancelled_crops' => $this->state['cancelled_crops'],
            'crop' => $this->state['crop'] === null ? null : CultivationClock::project($this->state['crop'], $this->profile['crop'], $this->state['now'])];
    }

    public static function report(array $profile, array $config, string $scenario): array
    {
        EconomyProfile::validate($profile);
        if (($config['schema_version'] ?? null) !== 1 || $config['profile'] !== $profile['code'] || $config['timezone'] !== 'Europe/Moscow'
            || !array_key_exists($scenario, $config['scenarios']) || !is_int($config['order_lifetime_seconds']) || $config['order_lifetime_seconds'] < 1 || $config['order_lifetime_seconds'] > 604800
            || !is_string($config['municipal_budget_cr']) || !is_string($config['starts_at'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+03:00$/D', $config['starts_at'])) throw new \InvalidArgumentException('Invalid economy scenario configuration.');
        $self = new self(); $self->profile = $profile; $self->config = $config;
        $self->config['finite_supplies'] = in_array($scenario, $config['finite_supply_scenarios'] ?? [], true);
        $start = new \DateTimeImmutable($config['starts_at']);
        if ($start->format('c') !== $config['starts_at']) throw new \InvalidArgumentException('Invalid fixed scenario date.');
        $self->config['start'] = $start->getTimestamp();
        $nightEnabled = in_array($scenario, $config['night_scenarios'] ?? [], true);
        $health = ['severity' => 0, 'recovery_progress' => 0, 'onset_at' => null, 'exposure_nights' => 0, 'next_sequence' => 0];
        if ($nightEnabled) {
            if (($config['night_policy']['status'] ?? null) !== 'analysis_only_not_published') throw new \InvalidArgumentException('Expected an explicit offline night-policy example.');
            $epoch = $config['night_policy']['activated_at'];
            if (!is_string($epoch) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+03:00$/D', $epoch)) throw new \InvalidArgumentException('Expected a fixed night epoch.');
            $activation = new \DateTimeImmutable($epoch);
            if ($activation->format('c') !== $epoch) throw new \InvalidArgumentException('Invalid night epoch.');
            $self->config['night_rules'] = WorldNights::rules($config['night_policy']) + ['activated_at' => $activation->getTimestamp()];
            $policy = $self->config['night_rules'];
            $health['next_sequence'] = NightCalendar::firstStartingAt($policy, max($self->config['start'], $policy['activated_at']) + $policy['day_seconds']);
        }
        $reserve = self::money('0'); $orders = [];
        $eligible = [$profile['crop']['yield'] => true];
        foreach ($profile['actions'] as $action) if ($action['quota'] === 'extraction_daily') foreach ($action['output'] as $code => $amount) $eligible[$code] = true;
        foreach ($config['orders'] as $code => $order) {
            if (!isset($profile['items'][$code], $eligible[$code]) || $profile['items'][$code]['kind'] !== 'material' || $profile['items'][$code]['sale_price_cr'] === null || $order['unit_price_cr'] !== $profile['items'][$code]['sale_price_cr']
                || !is_int($order['quantity']) || $order['quantity'] < 1 || $order['quantity'] > 10000) throw new \InvalidArgumentException('Invalid funded raw-material order: ' . $code);
            $reserve = $reserve->add(self::money($order['unit_price_cr'])->multiply($order['quantity'])); $orders[$code] = $order['quantity'];
        }
        foreach ($profile['goals'] as $goal) $reserve = $reserve->add(self::money($goal['credits_cr']));
        $wallet = self::money(($profile['starter']['funding'] ?? '') === 'parent_budget' ? '0' : $profile['starter']['credits_cr']); $municipal = self::money($config['municipal_budget_cr']);
        $self->initialMoney = $wallet->add($municipal);
        if ($municipal->isNegative()) throw new \InvalidArgumentException('Negative initial municipal funding.');
        $self->state = ['now' => $self->config['start'], 'wallet' => $wallet, 'municipal' => $municipal, 'municipal_reserved' => $reserve, 'loss_sink' => self::money('0'), 'craft_fee_sink' => self::money('0'), 'flows' => [], 'step' => 0,
            'stock' => $profile['starter']['items'], 'tools' => [], 'stations' => [], 'objects' => [], 'budgets' => [], 'tax_reserved' => [], 'tax_carry' => [], 'receipts' => [],
            'placements' => [], 'orders' => $orders, 'daily' => [], 'supply_claims' => [], 'goals' => [], 'crafted' => [], 'craft_times' => [], 'gathered' => [], 'action_counts' => [],
            'extraction' => [], 'sales' => [], 'explored' => [], 'paid_cells' => [], 'cancelled_crops' => [], 'facts' => ['city_joined' => true], 'crop' => null, 'peak_slots' => 0, 'checkpoints' => [],
            'night_enabled' => $nightEnabled, 'health' => $health, 'night_history' => [], 'beds' => [], 'lodging' => []];
        foreach ($profile['starter']['items'] as $code => $quantity) if ($profile['items'][$code]['kind'] === 'tool') $self->state['tools'][$code] = array_fill(0, $quantity, $profile['operating_costs']['equipment_max_durability']);
        $trace = []; $failure = null;
        try { self::need($municipal->compare($reserve) >= 0, 'INSUFFICIENT_MUNICIPAL_RESERVE', $reserve->decimal()); $self->capacity(); }
        catch (\DomainException $exception) { $failure = ['step' => 0, 'reason' => $exception->getMessage()]; }
        foreach (self::routeSteps($config, $scenario, $self->config['finite_supplies']) as $index => $step) {
            if ($failure !== null) break;
            $before = $self->state;
            try { $self->state['step'] = $index + 1; $self->apply($step); self::need($self->summary()['money_conserved'], 'MONEY_NOT_CONSERVED'); }
            catch (\DomainException $exception) {
                $self->state = $before; $failure = ['step' => $index + 1, 'input' => $step, 'reason' => $exception->getMessage()];
                if ($step['action'] === 'build' && isset($profile['objects'][$step['code']], $self->state['budgets'][$step['parent']])) {
                    $object = $profile['objects'][$step['code']]; $available = $self->available($step['parent'])->add($self->state['wallet']); $price = self::money($object['price_cr']); $missing = [];
                    foreach ($object['materials'] as $code => $amount) if (($self->state['stock'][$code] ?? 0) < $amount) $missing[$code] = $amount - ($self->state['stock'][$code] ?? 0);
                    $failure['requirements'] = ['price_cr' => $price->decimal(), 'available_cr' => $available->decimal(),
                        'shortfall_cr' => $price->compare($available) > 0 ? $price->subtract($available)->decimal() : '0.0000', 'missing_materials' => $missing];
                }
                break;
            }
            $trace[] = ['step' => $index + 1, 'input' => $step, 'at' => $self->summary()['at'], 'wallet_cr' => $self->state['wallet']->decimal()];
        }
        return ['profile' => $profile['code'], 'scenario' => $scenario, 'supply_model' => $self->config['finite_supplies'] ? 'finite_current_runtime' : 'historical_daily_model', 'status' => $failure === null ? 'completed' : 'blocked', 'applied' => false,
            'initial_total_cr' => $self->initialMoney->decimal(), 'failure' => $failure, 'trace' => $trace, 'flows' => $self->state['flows'], 'checkpoints' => $self->state['checkpoints'], 'night_history' => $self->state['night_history'], 'final' => $self->summary(),
            'limitations' => ['candidate_not_live_game', $nightEnabled ? 'explicit_example_night_rules_not_a_live_policy' : 'full_health_efficiency_assumed_not_a_night_survival_test',
                'initial_municipal_funding_and_uncompeted_orders_are_test_setup_prerequisites', 'no_market_or_additional_personal_transfers',
                'one_object_per_code_and_serial_labor_with_heartbeat_at_most_60_seconds', 'tax_reserved_unspendable_remittance_not_simulated',
                'crop_labor_120_plus_60_seconds_is_a_conservative_allowance', 'backpack_stacks_compacted_after_each_operation',
                'skills_above_level_one_and_general_repair_routes_require_separate_scenarios', 'night_penalty_affects_daily_and_crop_yield_source_work_matches_current_mining',
                'constructed_lodging_only_no_equipment_shelter_or_retroactive_assignment', 'single_assignment_covers_whole_night_conservative',
                'map_capacity_counts_rights_without_assigning_each_object_coordinates', 'only_starter_site_to_owned_child_garden_or_stone_plot_grants_modelled']];
    }
}
