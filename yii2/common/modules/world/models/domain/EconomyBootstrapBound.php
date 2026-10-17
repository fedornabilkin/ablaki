<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\value\Money;

/** Optimistic potential bound before the first ingot, independent of action order. No game writes. */
final class EconomyBootstrapBound
{
    private static function value(array $items, array $weights): Money
    {
        $sum = Money::parse('0');
        foreach ($items as $code => $quantity) {
            if (!isset($weights[$code])) throw new \InvalidArgumentException('Unpriced raw requirement: ' . $code);
            $sum = $sum->add(Money::parse($weights[$code])->multiply($quantity));
        }
        return $sum;
    }
    private static function needsIngot(string $item, array $recipes, array $path = []): bool
    {
        if ($item === 'classic-ingot') return true;
        if (isset($path[$item])) throw new \InvalidArgumentException('Cyclic bootstrap recipe.');
        if (!isset($recipes[$item])) return false;
        $path[$item] = true; $row = $recipes[$item];
        foreach (array_merge(array_keys($row['ingredients']), $row['tools'], $row['station'] === null ? [] : [$row['station']]) as $required) {
            if (self::needsIngot($required, $recipes, $path)) return true;
        }
        return false;
    }
    private static function expand(string $item, int $quantity, array $recipes, array &$spare, array &$raw, array $path = []): void
    {
        if ($quantity < 1 || $quantity > 10000 || isset($path[$item])) throw new \InvalidArgumentException('Invalid or cyclic bootstrap demand.');
        if (!isset($recipes[$item])) { $raw[$item] = ($raw[$item] ?? 0) + $quantity; return; }
        $take = min($quantity, $spare[$item] ?? 0); $quantity -= $take; $spare[$item] = ($spare[$item] ?? 0) - $take;
        if (!$quantity) return;
        $path[$item] = true; $recipe = $recipes[$item]; $batches = intdiv($quantity + $recipe['quantity'] - 1, $recipe['quantity']);
        $spare[$item] += $batches * $recipe['quantity'] - $quantity;
        foreach ($recipe['ingredients'] as $code => $amount) self::expand($code, $amount * $batches, $recipes, $spare, $raw, $path);
    }
    public static function report(array $profile, array $contract, int $days = 2): array
    {
        EconomyProfile::validate($profile);
        if ($days < 1 || $days > 7) throw new \InvalidArgumentException('Bound covers one to seven Moscow calendar days.');
        if (count($contract['objects']) !== count(array_unique($contract['objects']))) throw new \InvalidArgumentException('Duplicate mandatory object.');
        $recipes = []; $weights = []; $frontier = []; $excluded = [];
        foreach ($profile['recipes'] as $row) {
            if (isset($recipes[$row['output']])) throw new \InvalidArgumentException('Bound requires a unique published recipe per output.');
            $recipes[$row['output']] = $row;
        }
        foreach ($profile['actions'] as $code => $row) {
            if ($row['quota'] !== 'extraction_daily') continue;
            foreach ($row['output'] as $item => $quantity) if ($profile['items'][$item]['sale_price_cr'] !== null) $weights[$item] = $profile['items'][$item]['sale_price_cr'];
        }
        $crop = $profile['crop']; $seed = $recipes[$crop['seed']];
        if ($seed['ingredients'] !== [$crop['yield'] => 1] || $seed['quantity'] !== 2 || $crop['seed_quantity'] !== 1
            || $crop['water_actions_per_cycle'] !== 2 || $crop['water_quantity_per_action'] !== 1) throw new \InvalidArgumentException('Unsupported crop/seed potential.');
        $weights[$crop['yield']] = $profile['items'][$crop['yield']]['sale_price_cr'];
        if (!is_string($weights[$crop['yield']])) throw new \InvalidArgumentException('Crop output needs a priced reference order.');
        $weights[$crop['seed']] = Money::parse($weights[$crop['yield']])->divideExact(2)->decimal();
        foreach ([$profile['starter']['items'], $profile['daily']['items'], $profile['goals']['day-1']['items']] as $grant) {
            foreach ($grant as $item => $quantity) {
                if (!isset($weights[$item]) && !in_array($item, ['classic-hammer', 'world-explorer-elixir'], true)) throw new \InvalidArgumentException('Non-raw initial capital requires revisiting the bound: ' . $item);
            }
        }
        // Seeds are not purchasable under the reference raw orders. Their potential is half a carrot.
        foreach ($profile['recipes'] as $code => $row) if (isset($weights[$row['output']]) && $row['output'] !== $crop['seed']) throw new \InvalidArgumentException('New raw-output recipe requires revisiting the bound: ' . $code);
        $best = Money::parse('0'); $maxWater = 0;
        foreach ($profile['actions'] as $code => $row) {
            if ($row['quota'] !== 'extraction_daily') continue;
            $blocked = false;
            foreach ($row['tools'] as $tool) if (self::needsIngot($tool, $recipes)) $blocked = true;
            foreach (array_keys($profile['objects'][$row['node']]['materials']) as $material) if (self::needsIngot($material, $recipes)) $blocked = true;
            if ($blocked) { $excluded[] = $code; continue; }
            $priced = true; foreach ($row['output'] as $item => $quantity) if (!isset($weights[$item])) $priced = false;
            if (!$priced) { $excluded[] = $code; continue; }
            $value = self::value($row['output'], $weights); $frontier[$code] = $value->decimal();
            if ($value->compare($best) > 0) $best = $value;
            $maxWater = max($maxWater, $row['output'][$crop['water']] ?? 0);
        }
        $raw = []; $spare = []; $buildingCost = Money::parse('0');
        foreach ($contract['objects'] as $code) {
            if (!isset($profile['objects'][$code])) throw new \InvalidArgumentException('Unknown bootstrap object.');
            $buildingCost = $buildingCost->add(Money::parse($profile['objects'][$code]['price_cr']));
            foreach ($profile['objects'][$code]['materials'] as $item => $quantity) self::expand($item, $quantity, $recipes, $spare, $raw);
        }
        foreach ($contract['crafted_items'] as $item => $quantity) {
            if (!is_int($quantity)) throw new \InvalidArgumentException('Crafted target quantity must be an integer.');
            self::expand($item, $quantity, $recipes, $spare, $raw);
        }
        ksort($raw);
        $initial = [];
        foreach ($profile['starter']['items'] as $code => $quantity) if (isset($weights[$code])) $initial[$code] = $quantity;
        foreach ($profile['daily']['items'] as $code => $quantity) if (isset($weights[$code])) $initial[$code] = ($initial[$code] ?? 0) + $quantity * $days;
        $batches = $profile['limits']['extraction_daily']['maximum'] * $days;
        $water = ($initial[$crop['water']] ?? 0) + $batches * $maxWater;
        $cycles = intdiv($water, 2);
        $cropGain = self::value([$crop['yield'] => $crop['yield_quantity']], $weights)->subtract(self::value([$crop['seed'] => 1, $crop['water'] => 2], $weights));
        $fees = Money::parse($crop['dig_fee_cr'])->add(Money::parse($crop['sow_fee_cr']))->add(Money::parse($crop['water_fee_cr']))->add(Money::parse($crop['harvest_fee_cr']));
        $net = $cropGain->subtract($fees); $cropBonus = $net->isNegative() ? Money::parse('0') : $net->multiply($cycles);
        // A missed watering gives at most one carrot; ensure it cannot increase the optimistic bonus.
        $missed = self::value([$crop['yield'] => max(1, intdiv($crop['yield_quantity'], 2))], $weights)
            ->subtract(self::value([$crop['seed'] => 1, $crop['water'] => 1], $weights))
            ->subtract(Money::parse($crop['dig_fee_cr']))->subtract(Money::parse($crop['sow_fee_cr']))->subtract(Money::parse($crop['harvest_fee_cr']));
        if (!$missed->isNegative() || $net->compare(Money::parse('0.2500')) > 0) throw new \InvalidArgumentException('Crop rules require a new optimistic bound.');
        $rawCost = self::value($raw, $weights); $initialValue = self::value($initial, $weights);
        $upper = Money::parse($profile['starter']['credits_cr'])->add(Money::parse($profile['goals']['day-1']['credits_cr']))
            ->add($initialValue)->add($best->multiply($batches))->subtract($rawCost)->add($cropBonus);
        $gap = $buildingCost->subtract($upper);
        return ['profile' => $profile['code'], 'days_moscow' => $days, 'status' => $gap->isNegative() || $gap->isZero() ? 'not_ruled_out' : 'impossible_under_fixed_assumptions', 'applied' => false,
            'required_raw_items' => $raw, 'required_raw_value_cr' => $rawCost->decimal(), 'mandatory_building_prices_cr' => $buildingCost->decimal(),
            'initial_raw_potential_cr' => $initialValue->decimal(), 'seed_potential_cr' => $weights[$crop['seed']], 'source_frontier_cr' => $frontier, 'excluded_sources' => $excluded,
            'best_source_batch_cr' => $best->decimal(), 'maximum_source_batches' => $batches, 'maximum_water_units' => $water, 'maximum_crop_cycles' => $cycles,
            'crop_gain_after_fees_cr' => $net->decimal(), 'maximum_crop_bonus_cr' => $cropBonus->decimal(),
            'cash_upper_bound_cr' => $upper->decimal(), 'minimum_shortfall_cr' => $gap->isNegative() ? '0.0000' : $gap->decimal(),
            'relaxations' => ['zero_taxes_and_treasury_loss', 'full_efficiency_and_no_wear_or_craft_fees', 'unlimited_funded_raw_sales_except_seeds',
                'every_batch_gets_best_source_value_and_maximum_water_simultaneously', 'no_time_storage_placement_or_crop_bed_limit', 'meadow_price_and_materials_omitted',
                'all_daily_grants_available_from_start', 'gross_first_goal_reward_available_before_its_facts'],
            'scope' => ['reference_three_raw_orders_no_seed_sales', 'no_market_paid_external_work_gifts_or_repeated_starter_grants',
                'mandatory_targets_as_listed_in_bound_contract', 'first_ingot_not_funded_by_its_own_reward_or_iron_pickaxe', 'bound_does_not_prove_a_feasible_route_when_not_ruled_out',
                'two_Moscow_calendar_days_not_arbitrary_48_hour_window']];
    }
}
