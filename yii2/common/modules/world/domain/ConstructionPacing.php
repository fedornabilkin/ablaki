<?php
namespace common\modules\world\domain;

use common\modules\world\modules\economy\value\Money;

/** Offline construction totals from the same profile and objectives used by the game. */
final class ConstructionPacing
{
    public static function report(array $profile): array
    {
        $route = []; $stages = [];
        foreach (DevelopmentRoute::stages($profile) as $stage) {
            $counts = [];
            foreach ($stage['steps'] as $step) {
                if (!str_starts_with($step['code'], 'built:')) continue;
                $code = explode('@', substr($step['code'], 6))[0];
                $counts[$code] = ($counts[$code] ?? 0) + $step['required'];
                $route[$code] = ($route[$code] ?? 0) + $step['required'];
            }
            $stages[$stage['code']] = self::total($profile, $counts);
        }
        $total = self::total($profile, $route);
        $objects = [];
        foreach ($profile['objects'] as $code => $object) {
            $solo = (int)$object['work_seconds'];
            $objects[$code] = ['name' => $object['name'], 'price_cr' => $object['price_cr'],
                'work_seconds' => $solo, 'materials' => $object['materials'],
                'team_elapsed_seconds' => [1 => $solo, 2 => intdiv($solo + 1, 2), 4 => intdiv($solo + 3, 4)]];
        }
        $sessions = [];
        foreach ([60, 90, 120] as $minutes) {
            $sessions[] = ['minutes_per_day' => $minutes,
                'construction_only_days' => intdiv($total['work_seconds'] + $minutes * 60 - 1, $minutes * 60)];
        }
        $starter = self::total($profile, array_fill_keys(['starter-shelter', 'garden', 'garden-bed', 'mine', 'forge'], 1));
        return [
            'basis' => 'profile_person_seconds', 'applied' => false,
            'catalog' => self::total($profile, array_fill_keys(array_keys($objects), 1)),
            'route' => $total, 'stages' => $stages, 'objects' => $objects,
            'starter_core' => $starter + [
                'city_start_cr' => $profile['starter']['credits_cr'],
                'remaining_before_materials_cr' => Money::parse($profile['starter']['credits_cr'])->subtract(Money::parse($starter['price_cr']))->decimal(),
            ],
            'solo_construction_sessions' => $sessions,
            'calendar_completion_days' => null,
            'excludes' => ['materials_acquisition', 'land', 'equipment', 'food', 'craft_fees', 'hired_labor', 'repairs', 'travel', 'npc_liquidity'],
            'team_assumptions' => ['simultaneous_active_workers', 'full_efficiency', 'sufficient_helper_budget', 'no_breaks'],
        ];
    }

    private static function total(array $profile, array $counts): array
    {
        $price = Money::parse('0'); $seconds = 0; $materials = [];
        foreach ($counts as $code => $count) {
            $object = $profile['objects'][$code] ?? null;
            if (!$object) throw new \InvalidArgumentException('Missing construction terms: ' . $code);
            $price = $price->add(Money::parse($object['price_cr'])->multiply($count));
            $seconds += $object['work_seconds'] * $count;
            foreach ($object['materials'] as $item => $quantity) $materials[$item] = ($materials[$item] ?? 0) + $quantity * $count;
        }
        ksort($materials);
        return ['templates' => count($counts), 'buildings' => array_sum($counts),
            'price_cr' => $price->decimal(), 'work_seconds' => $seconds, 'materials' => $materials];
    }
}
