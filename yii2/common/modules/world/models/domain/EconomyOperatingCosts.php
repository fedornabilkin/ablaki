<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\EquipmentExposure;
use common\modules\world\modules\economy\value\Money;

/** Offline valuations only. A reserve is neither an actual debit nor an available shop/repair offer. */
final class EconomyOperatingCosts
{
    private static function value(array $quantities, array $items): ?Money
    {
        $sum = Money::parse('0');
        foreach ($quantities as $code => $quantity) {
            if ($items[$code]['sale_price_cr'] === null) return null;
            $sum = $sum->add(Money::parse($items[$code]['sale_price_cr'])->multiply($quantity));
        }
        return $sum;
    }

    private static function equipment(array $profile, array $tools, ?string $station, int $base, string $exposure): array
    {
        $roles = array_fill_keys($tools, false);
        if ($station !== null) $roles[$station] = true;
        $maximum = $profile['operating_costs']['equipment_max_durability'];
        $total = Money::parse('0'); $entries = [];
        foreach ($roles as $code => $isStation) {
            // Portable tools stay carried. Only placed stations inherit the selected exposure.
            $location = $isStation ? $exposure : 'carried';
            $wear = EquipmentExposure::workCost($location, $base, 1, $isStation);
            $price = $profile['items'][$code]['sale_price_cr'];
            $reserve = $wear === 0 ? Money::parse('0') : ($price === null ? null : Money::parse($price)->ratioCeil($wear, $maximum));
            $entries[$code] = ['station' => $isStation, 'exposure' => $location, 'minimum_durability' => max(1, $wear),
                'work_wear' => $wear, 'replacement_reserve_cr' => $reserve ? $reserve->decimal() : null];
            $total = $total !== null && $reserve !== null ? $total->add($reserve) : null;
        }
        return ['equipment' => $entries, 'replacement_reserve_cr' => $total ? $total->decimal() : null];
    }

    private static function margin(?Money $revenue, ?Money $materials, Money $fee, ?string $reserve): array
    {
        $cost = $materials !== null && $reserve !== null ? $materials->add($fee)->add(Money::parse($reserve)) : null;
        return ['batch_sale_value_cr' => $revenue ? $revenue->decimal() : null,
            'materials_sale_value_cr' => $materials ? $materials->decimal() : null, 'fee_cr' => $fee->decimal(),
            'margin_cost_basis_cr' => $cost ? $cost->decimal() : null,
            'margin_after_work_reserve_cr' => $revenue !== null && $cost !== null ? $revenue->subtract($cost)->decimal() : null];
    }

    /** Same remaining-balance loss, fractional carry and collection tax as TreasuryLedger.
     * This is one receipt and a fresh tax checkpoint, not a projection of live accounts.
     */
    public static function treasury(array $policy, int $age): array
    {
        if ($age < 0 || $age > 604800 || !is_int($policy['protected_seconds']) || $policy['protected_seconds'] < 0
            || !is_int($policy['loss_period_seconds']) || $policy['loss_period_seconds'] < 1
            || !is_int($policy['loss_rate_bps']) || $policy['loss_rate_bps'] < 0 || $policy['loss_rate_bps'] > 10000
            || !is_int($policy['rate_bps']) || $policy['rate_bps'] < 0 || $policy['rate_bps'] > 10000) {
            throw new \InvalidArgumentException('Invalid bounded treasury projection.');
        }
        $gross = Money::parse($policy['gross_cr']);
        if ($gross->isNegative()) throw new \InvalidArgumentException('Negative receipt.');
        $remaining = $gross; $carry = 0; $processed = 0;
        $periods = $policy['loss_rate_bps'] === 0 ? 0 : max(0, intdiv(max(0, $age - $policy['protected_seconds']), $policy['loss_period_seconds']));
        for ($i = 0; $i < $periods && !$remaining->isZero(); $i++) {
            $part = $remaining->portion($policy['loss_rate_bps'], $carry);
            $remaining = $remaining->subtract($part['amount']); $carry = $part['carry'];
            $processed++;
        }
        $tax = $remaining->portion($policy['rate_bps']);
        return ['gross_cr' => $gross->decimal(), 'age_seconds' => $age, 'loss_periods' => $processed,
            'lost_cr' => $gross->subtract($remaining)->decimal(), 'collected_to_budget_cr' => $remaining->decimal(),
            'reserved_for_parent_cr' => $tax['amount']->decimal(),
            'available_object_budget_cr' => $remaining->subtract($tax['amount'])->decimal(),
            'personal_wallet_income_cr' => '0.0000'];
    }

    public static function report(array $profile): array
    {
        $policy = $profile['operating_costs']; $items = $profile['items']; $recipes = []; $actions = [];
        foreach ($profile['recipes'] as $code => $row) {
            $locations = [];
            foreach (['outdoor', 'covered', 'indoor'] as $location) {
                $wear = self::equipment($profile, $row['tools'], $row['station'], $policy['craft_base_work_wear'], $location);
                $locations[$location] = $wear + self::margin(self::value([$row['output'] => $row['quantity']], $items),
                    self::value($row['ingredients'], $items), Money::parse($row['fee_cr']), $wear['replacement_reserve_cr']);
            }
            $recipes[$code] = $locations;
        }
        foreach ($profile['actions'] as $code => $row) {
            $base = $code === 'harvest-carrot' ? $policy['crop_dig_tool_wear_per_cycle'] : $policy['source_tool_wear_per_batch'];
            $wear = self::equipment($profile, $row['tools'], null, $base, 'carried');
            $actions[$code] = $wear + self::margin(self::value($row['output'], $items), self::value($row['ingredients'], $items),
                Money::parse($row['fee_cr']), $wear['replacement_reserve_cr']);
        }
        $crop = $profile['crop']; $seedRecipe = $profile['recipes']['starter-carrot-seeds'];
        $retained = $seedRecipe['ingredients'][$crop['yield']]; $sold = $crop['yield_quantity'] - $retained;
        if ($sold < 0 || $seedRecipe['output'] !== $crop['seed']) throw new \InvalidArgumentException('Carrot seed loop is not sustainable.');
        $sales = self::value([$crop['yield'] => $sold], $items);
        $fees = Money::parse($crop['dig_fee_cr'])->add(Money::parse($crop['water_fee_cr']))
            ->add(Money::parse($crop['sow_fee_cr']))->add(Money::parse($crop['harvest_fee_cr']))->add(Money::parse($seedRecipe['fee_cr']));
        $loopOutputs = [$crop['seed'] => $seedRecipe['quantity']];
        if ($sold > 0) $loopOutputs[$crop['yield']] = $sold;
        $loop = self::margin(self::value($loopOutputs, $items), self::value($profile['actions']['harvest-carrot']['ingredients'], $items),
            $fees, $actions['harvest-carrot']['replacement_reserve_cr']);
        return ['recipes' => $recipes, 'actions' => $actions,
            'carrot_seed_loop' => $loop + ['retained_carrots' => $retained, 'sold_carrots' => $sold,
                'produced_seeds' => $seedRecipe['quantity'], 'consumed_seeds' => $crop['seed_quantity'],
                'gross_sales_if_funded_cr' => $sales ? $sales->decimal() : null,
                'sales_less_fees_before_tax_cr' => $sales ? $sales->subtract($fees)->decimal() : null],
            'daily_exposure_wear' => EquipmentExposure::dailyRates(),
            'new_objects_daily_upkeep_cr' => $policy['new_objects_daily_upkeep_cr'],
            'treasury_examples' => ['immediate' => self::treasury($policy['treasury_example'], 0),
                'protected_boundary' => self::treasury($policy['treasury_example'], $policy['treasury_example']['protected_seconds']),
                'after_one_day' => self::treasury($policy['treasury_example'], 86400)],
            'guaranteed_daily_income' => null,
            'limitations' => ['valuations_not_actual_sales_or_wallet_income', 'work_reserves_exclude_passive_wear_and_capital',
                'passive_wear_depends_on_duration_location_and_rounding', 'unpriced_equipment_keeps_margin_unknown',
                'funded_demand_quantity_and_order_expiry_require_BAL_scenarios', 'existing_contracts_are_not_repriced']];
    }
}
