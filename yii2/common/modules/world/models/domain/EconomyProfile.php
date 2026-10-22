<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\value\Money;

/** Read-only candidate audit. Loading a profile never publishes it or changes game state. */
final class EconomyProfile
{
    public static function load(?string $path = null): array
    {
        $path = $path ?? dirname(__DIR__, 2) . '/data/economy/closed-test-v1.json';
        if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Cannot read economy profile.');
        $json = file_get_contents($path);
        if ($json === false) throw new \RuntimeException('Cannot read economy profile.');
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \InvalidArgumentException('Expected an economy profile object.');
        return $data;
    }

    private static function quantities(array $quantities, array $definitions, string $field): void
    {
        foreach ($quantities as $code => $quantity) {
            if (!isset($definitions[$code]) || !is_int($quantity) || $quantity < 1 || $quantity > 10000) {
                throw new \InvalidArgumentException('Invalid quantity or reference in ' . $field . ': ' . $code);
            }
        }
    }

    private static function references(array $codes, array $definitions, string $field): void
    {
        foreach ($codes as $code) {
            if (!is_string($code) || !isset($definitions[$code])) throw new \InvalidArgumentException('Unknown reference in ' . $field);
        }
    }

    private static function moneyFields(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) self::moneyFields($value);
            elseif (is_string($key) && substr($key, -3) === '_cr') {
                if ($value === null && in_array($key, ['reference_price_cr', 'sale_price_cr'], true)) continue;
                if (!is_string($value) || Money::parse($value)->isNegative()) throw new \InvalidArgumentException('Expected non-negative exact credits in ' . $key);
            }
        }
    }

    public static function validate(array $data): void
    {
        if (($data['schema_version'] ?? null) !== 1 || ($data['currency'] ?? null) !== 'Cr'
            || ($data['money_scale'] ?? null) !== 4 || ($data['time_basis'] ?? null) !== 'real_seconds'
            || ($data['crafting_mode'] ?? null) !== 'instant' || ($data['payments_enabled'] ?? null) !== false
            || ($data['bank_enabled'] ?? null) !== false) throw new \InvalidArgumentException('Unsupported economy profile contract.');
        foreach (['items', 'objects', 'recipes', 'actions', 'starter', 'daily', 'map', 'limits', 'crop', 'goals', 'operating_costs'] as $field) {
            if (empty($data[$field]) || !is_array($data[$field])) throw new \InvalidArgumentException('Missing profile section: ' . $field);
        }
        foreach (['items', 'objects', 'recipes', 'actions'] as $field) {
            foreach ($data[$field] as $code => $row) {
                if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $code) || !is_array($row)) throw new \InvalidArgumentException('Invalid profile code in ' . $field);
            }
        }
        self::moneyFields($data);
        $access = $data['closed_test'] ?? [];
        if (!is_bool($access['restricted'] ?? null) || ($access['permission'] ?? null) !== 'world-closed-test' || ($access['role'] ?? null) !== 'world-tester'
            || !is_string($access['feedback_path'] ?? null) || !preg_match('~^/forum(?:/read/[1-9][0-9]*)?$~D', $access['feedback_path'])) throw new \InvalidArgumentException('Closed-test admission requires built-in RBAC and an internal forum path.');
        foreach ($data['items'] as $food) if (isset($food['restore_health'])) {
            if (!is_int($food['restore_health']) || $food['restore_health'] < 1 || $food['restore_health'] > 100) throw new \InvalidArgumentException('Invalid food restoration.');
            foreach (['backpack_restore_hp' => [1, 8], 'repeat_limit' => [1, 10], 'repeat_seconds' => [3600, 86400]] as $key => $range)
                if (isset($food[$key]) && (!is_int($food[$key]) || $food[$key] < $range[0] || $food[$key] > $range[1])) throw new \InvalidArgumentException('Invalid food rule: ' . $key);
        }
        if (isset($data['health']) && ($data['health']['maximum_hp'] !== 100 || $data['health']['unprotected_night_damage_hp'] !== 15
            || $data['health']['food_removes_night_illness'] !== false || $data['health']['zero_hp_destroys_items'] !== false)) throw new \InvalidArgumentException('Unsupported closed-test health contract.');
        foreach (['hunger_after_seconds' => WorldFood::HUNGER_AFTER, 'hunger_period_seconds' => WorldFood::HUNGER_PERIOD, 'hunger_damage_hp' => WorldFood::HUNGER_DAMAGE] as $field => $expected)
            if (isset($data['health'][$field]) && $data['health'][$field] !== $expected) throw new \InvalidArgumentException('Unsupported hunger contract: ' . $field);
        $costs = $data['operating_costs'];
        if ($costs['equipment_max_durability'] !== 100 || $costs['craft_base_work_wear'] !== 0
            || $costs['source_tool_wear_per_batch'] !== 1 || $costs['crop_dig_tool_wear_per_cycle'] !== 1
            || !Money::parse($costs['new_objects_daily_upkeep_cr'])->isZero() || $costs['new_objects_passive_condition_loss'] !== 0
            || $costs['existing_published_terms'] !== 'preserved' || $costs['treasury_example']['guaranteed_daily_income'] !== null
            || $costs['treasury_example']['funding'] !== 'existing_finite_reserved_settlement_purchase_order'
            || $costs['treasury_example']['income_destination'] !== 'object_treasury_then_object_budget_not_personal_wallet') {
            throw new \InvalidArgumentException('Operating costs must match the candidate and existing wear rules.');
        }
        EconomyOperatingCosts::treasury($costs['treasury_example'], 0);
        $items = $data['items'];
        foreach ($items as $code => $row) {
            if (!isset($row['stack_size']) || !is_int($row['stack_size']) || $row['stack_size'] < 1 || $row['stack_size'] > 10000) throw new \InvalidArgumentException('Invalid stack size: ' . $code);
            foreach (['reference_price_cr', 'sale_price_cr'] as $field) {
                if (!array_key_exists($field, $row)) throw new \InvalidArgumentException('Missing price declaration: ' . $code);
            }
        }
        foreach (['starter', 'daily'] as $section) self::quantities($data[$section]['items'], $items, $section);
        if (isset($data['daily']['claim_limit']) && (!is_int($data['daily']['claim_limit']) || $data['daily']['claim_limit'] < 1 || $data['daily']['claim_limit'] > 30))
            throw new \InvalidArgumentException('Daily allowance must contain 1–30 decreasing claims per item.');
        if (isset($data['daily']['initial_multiplier']) && (!is_int($data['daily']['initial_multiplier']) || $data['daily']['initial_multiplier'] < ($data['daily']['claim_limit'] ?? 5) || $data['daily']['initial_multiplier'] > 30))
            throw new \InvalidArgumentException('Initial supply multiplier must cover every decreasing claim.');
        foreach ($data['daily']['suppressed_by'] ?? [] as $code => $objects) {
            if (!isset($data['daily']['items'][$code]) || !is_array($objects)) throw new \InvalidArgumentException('Unknown supply resource.');
            self::references($objects, $data['objects'], 'daily production');
        }
        if ($data['starter']['credit_source'] !== 'existing_transfer' || !Money::parse($data['daily']['credits_cr'])->isZero()) throw new \InvalidArgumentException('Starter uses existing transfers; daily supplies contain items only.');
        foreach ($data['daily']['items'] as $code => $quantity) {
            if ($items[$code]['reference_price_cr'] === null || $items[$code]['sale_price_cr'] === null) throw new \InvalidArgumentException('Daily valuation is incomplete.');
        }
        self::references($data['bootstrap_targets'], $items, 'bootstrap_targets');
        self::references($data['first_day_objects'], $data['objects'], 'first_day_objects');
        foreach ($data['objects'] as $code => $row) {
            if (isset($row['description']) && (!is_string($row['description']) || mb_strlen($row['description']) > 5000)) throw new \InvalidArgumentException('Invalid object description: ' . $code);
            if (!isset($row['price_cr']) || !is_int($row['work_seconds']) || $row['work_seconds'] < 1) throw new \InvalidArgumentException('Object needs a price and work duration: ' . $code);
            self::quantities($row['materials'], $items, $code);
            self::references($row['capability_requirements'] ?? [], $data['objects'], $code);
            if (isset($row['grow_duration_bps']) && (!is_int($row['grow_duration_bps']) || $row['grow_duration_bps'] < 1000 || $row['grow_duration_bps'] > 10000)) throw new \InvalidArgumentException('Invalid growth duration modifier: ' . $code);
            if (isset($row['event_protection']) && (($row['event_protection']['code'] ?? null) !== 'light_crop_damage' || ($row['event_protection']['max_event_loss_bps'] ?? null) !== 2500
                || !is_int($row['event_protection']['loss_reduction_bps'] ?? null) || $row['event_protection']['loss_reduction_bps'] < 0 || $row['event_protection']['loss_reduction_bps'] > 10000)) throw new \InvalidArgumentException('Invalid crop event protection: ' . $code);
        }
        foreach ($data['recipes'] as $code => $row) {
            if (!isset($row['fee_cr'])) throw new \InvalidArgumentException('Recipe needs a declared fee: ' . $code);
            self::quantities([$row['output'] => $row['quantity']], $items, $code);
            if (!$row['ingredients']) throw new \InvalidArgumentException('Crafting must consume ingredients: ' . $code);
            self::quantities($row['ingredients'], $items, $code);
            self::references($row['tools'], $items, $code);
            self::references($row['requires'], $data['recipes'], $code);
            self::references($row['station'] === null ? [] : [$row['station']], $items, $code);
            self::references($row['context'] === null ? [] : [$row['context']], $data['objects'], $code);
            if ($row['work_seconds'] !== 0) throw new \InvalidArgumentException('Timed crafting requires a separate contract.');
        }
        foreach ($data['actions'] as $code => $row) {
            if (!isset($row['fee_cr'])) throw new \InvalidArgumentException('Action needs a declared fee: ' . $code);
            self::references([$row['node']], $data['objects'], $code);
            if (!$row['output']) throw new \InvalidArgumentException('Resource action must produce an item.');
            self::quantities($row['output'], $items, $code);
            self::quantities($row['ingredients'], $items, $code);
            self::references($row['tools'], $items, $code);
            if (!is_int($row['work_seconds']) || $row['work_seconds'] < 0 || !is_int($row['cooldown_seconds'])
                || $row['cooldown_seconds'] < 0 || $row['work_seconds'] + $row['cooldown_seconds'] < 1) throw new \InvalidArgumentException('Resource action needs a bounded work or cooldown interval.');
        }
        $crop = $data['crop']; $harvest = $data['actions']['harvest-carrot'];
        $cropFee = Money::parse($crop['dig_fee_cr'])->add(Money::parse($crop['water_fee_cr']))
            ->add(Money::parse($crop['sow_fee_cr']))->add(Money::parse($crop['harvest_fee_cr']));
        if ($crop['water_actions_per_cycle'] !== 2 || $crop['water_quantity_per_action'] !== 1 || $crop['seed_quantity'] !== 1
            || $harvest['ingredients'] != [$crop['seed'] => 1, $crop['water'] => 2]
            || $harvest['output'] != [$crop['yield'] => $crop['yield_quantity']]
            || Money::parse($harvest['fee_cr'])->compare($cropFee) !== 0
            || $data['recipes']['starter-carrot-seeds']['ingredients'] != [$crop['yield'] => 1]) {
            throw new \InvalidArgumentException('Carrot aggregate must include the complete published cycle.');
        }
        foreach ($data['goals'] as $code => $row) {
            self::quantities($row['items'], $items, $code);
            if ($row['credit_source'] !== 'reserved_existing_settlement_budget' || $row['credit_destination'] !== 'existing_starter_site_treasury') throw new \InvalidArgumentException('Goal rewards need an existing funded budget and explicit treasury destination.');
        }
        $map = $data['map'];
        if ($map['starter_open_cells'] !== 9 || $map['max_cells'] !== 25 || $map['bounds'] !== [-2, 2]
            || $map['starter_open_square'] !== [-1, 1]) throw new \InvalidArgumentException('Inconsistent starter map dimensions.');
        foreach (['bulk_minimum' => [1, 25], 'discount_bps' => [0, 10000]] as $field => $range) {
            if (!is_int($map[$field] ?? null) || $map[$field] < $range[0] || $map[$field] > $range[1]) throw new \InvalidArgumentException('Invalid map pricing: ' . $field);
        }
        $limits = $data['limits'];
        if (($limits['crafting_hourly']['unit'] ?? null) !== 'recipe_batches' || ($limits['crafting_hourly']['window'] ?? null) !== 'rolling_3600_seconds'
            || ($limits['extraction_daily']['unit'] ?? null) !== 'successful_source_batches' || ($limits['active_labor_sessions'] ?? null) !== 1 || ($limits['daily_supply'] ?? null) !== 1) throw new \InvalidArgumentException('Unsupported quota semantics.');
        foreach ([$limits['crafting_hourly']['maximum'] ?? null, $limits['extraction_daily']['maximum'] ?? null, $limits['sales_daily']['operations'] ?? null,
            $limits['sales_daily']['same_item_per_operation'] ?? null, $limits['active_constructions'] ?? null] as $maximum) {
            if (!is_int($maximum) || $maximum < 1 || $maximum > 10000) throw new \InvalidArgumentException('Quota maxima must be positive integers.');
        }
        foreach ($limits['objects'] as $code => $maximum) if (!is_int($maximum) || $maximum < 1 || $maximum > 10000) throw new \InvalidArgumentException('Invalid object maximum: ' . $code);
        foreach (['chest_slots', 'mini_storage_slots', 'middle_storage_slots', 'warehouse_slots'] as $field) if (!is_int($data['storage'][$field] ?? null) || $data['storage'][$field] < 1 || $data['storage'][$field] > 100) throw new \InvalidArgumentException('Invalid storage capacity: ' . $field);
        foreach ($data['storage']['expansions'] ?? [] as $code => $pack) {
            if (!is_string($pack['price_cr'] ?? null) || Money::parse($pack['price_cr'])->isNegative()) throw new \InvalidArgumentException('Invalid storage expansion price: ' . $code);
            if (!is_string($code) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $code) || !in_array($pack['kind'] ?? null, ['backpack', 'chest', 'stockpile'], true)
                || !is_int($pack['slots'] ?? null) || $pack['slots'] < 1 || $pack['slots'] > 100 || !is_int($pack['maximum'] ?? null) || $pack['maximum'] < $pack['slots'] || $pack['maximum'] > 200
                || !array_key_exists('node_code', $pack) || ($pack['node_code'] !== null && !isset($data['objects'][$pack['node_code']]))) throw new \InvalidArgumentException('Invalid storage expansion: ' . $code);
        }
        if (!is_int($data['storage']['backpack_slots'] ?? null) || $data['storage']['backpack_slots'] < 1 || $data['storage']['backpack_slots'] > 100
            || ($data['storage']['existing_rights'] ?? null) !== 'preserve') throw new \InvalidArgumentException('Existing backpack rights must be preserved.');
    }

    private static function valuation(array $quantities, array $items, string $field): ?Money
    {
        $total = Money::parse('0');
        foreach ($quantities as $code => $quantity) {
            if ($items[$code][$field] === null) return null;
            $total = $total->add(Money::parse($items[$code][$field])->multiply($quantity));
        }
        return $total;
    }

    /** Physical dependency closure only: no free quantities, coins, slots, skill levels or time are inferred. */
    private static function reachability(array $data): array
    {
        $items = array_fill_keys(array_keys($data['starter']['items'] + $data['daily']['items']), true);
        $objects = ['starter-site' => true]; $recipes = []; $steps = [];
        $has = static function (array $codes, array $available): bool { return !array_diff($codes, array_keys($available)); };
        do {
            $changed = false;
            foreach ($data['objects'] as $code => $row) {
                if (isset($objects[$code]) || !$has(array_keys($row['materials']), $items) || !$has($row['capability_requirements'] ?? [], $objects)) continue;
                $objects[$code] = true; $steps[] = 'object:' . $code; $changed = true;
            }
            foreach ($data['actions'] as $code => $row) {
                if (!isset($objects[$row['node']]) || !$has(array_merge(array_keys($row['ingredients']), $row['tools']), $items)) continue;
                foreach ($row['output'] as $item => $quantity) {
                    if (!isset($items[$item])) { $items[$item] = true; $steps[] = 'action:' . $code . ' -> ' . $item; $changed = true; }
                }
            }
            foreach ($data['recipes'] as $code => $row) {
                $required = array_merge(array_keys($row['ingredients']), $row['tools'], $row['station'] === null ? [] : [$row['station']]);
                if (isset($recipes[$code]) || !$has($required, $items) || !$has($row['requires'], $recipes)
                    || ($row['context'] !== null && !isset($objects[$row['context']]))) continue;
                $recipes[$code] = true; $items[$row['output']] = true; $steps[] = 'recipe:' . $code; $changed = true;
            }
        } while ($changed);
        return ['steps' => $steps, 'blocked_targets' => array_values(array_diff($data['bootstrap_targets'], array_keys($items))),
            'blocked_recipes' => array_values(array_diff(array_keys($data['recipes']), array_keys($recipes)))];
    }

    public static function report(array $data): array
    {
        self::validate($data);
        $margins = [];
        foreach ($data['recipes'] as $code => $recipe) {
            $ingredients = self::valuation($recipe['ingredients'], $data['items'], 'sale_price_cr');
            $replacement = self::valuation($recipe['ingredients'], $data['items'], 'reference_price_cr');
            $revenue = self::valuation([$recipe['output'] => $recipe['quantity']], $data['items'], 'sale_price_cr');
            $fee = Money::parse($recipe['fee_cr']);
            $margins[$code] = ['quantity' => $recipe['quantity'], 'materials_sale_value_cr' => $ingredients ? $ingredients->decimal() : null,
                'materials_reference_value_cr' => $replacement ? $replacement->decimal() : null,
                'fee_cr' => $fee->decimal(), 'batch_sale_value_cr' => $revenue ? $revenue->decimal() : null,
                'margin_before_wear_cr' => $ingredients && $revenue ? $revenue->subtract($ingredients)->subtract($fee)->decimal() : null,
                'replacement_margin_before_wear_cr' => $replacement && $revenue ? $revenue->subtract($replacement)->subtract($fee)->decimal() : null];
        }
        $objects = Money::parse('0');
        foreach ($data['first_day_objects'] as $code) $objects = $objects->add(Money::parse($data['objects'][$code]['price_cr']));
        return ['profile' => $data['code'], 'status' => $data['status'], 'effective_from' => $data['effective_from'],
            'applied' => false, 'currency' => 'Cr',
            'counts' => ['items' => count($data['items']), 'objects' => count($data['objects']), 'recipes' => count($data['recipes']), 'actions' => count($data['actions'])],
            'starter_credits_cr' => $data['starter']['credits_cr'],
            'daily_grant' => ['actual_credits_cr' => $data['daily']['credits_cr'],
                'reference_value_cr' => self::valuation($data['daily']['items'], $data['items'], 'reference_price_cr')->decimal(),
                'sale_value_before_limits_cr' => self::valuation($data['daily']['items'], $data['items'], 'sale_price_cr')->decimal()],
            'first_day_objects_only_cr' => $objects->decimal(), 'recipe_margins' => $margins,
            'operating_costs' => EconomyOperatingCosts::report($data),
            'reachability' => self::reachability($data),
            'limitations' => ['candidate_not_published', 'reference_prices_are_not_a_shop',
                'sale_values_require_funded_demand_and_quotas', 'recipe_margins_exclude_wear_use_operating_costs_for_work_reserves',
                'passive_wear_repairs_and_capital_require_a_schedule',
                'reachability_is_not_a_day_or_week_simulation', 'quantities_time_skills_capacity_and_money_require_BAL_scenarios']];
    }
}
