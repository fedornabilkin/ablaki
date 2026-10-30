<?php
namespace common\modules\world\domain\events;

use common\modules\world\domain\WorldEntity;

/** Pure evaluation; persistence, authorization and applying a harvest remain with the command. */
final class CropDamageCalculator
{
    /** @param iterable<WorldEvent> $events Ordered, unique occurrences from the scoped snapshot. */
    public function calculate(iterable $events, WorldEntity $target, array $scopeIds, int $from, int $until, int $now): array
    {
        $effects = [];
        $total = 0;
        $rule = new CropDamageRule();
        $protection = $target->characteristic('event.protection');
        foreach ($events as $event) {
            if (!$event->affectsScope($scopeIds) || !$event->overlaps($from, $until, $now)) {
                continue;
            }
            $damage = $event->characteristic('crop.damage');
            if ($rule->error($damage) !== null) {
                continue;
            }
            $loss = $rule->loss($damage, $protection);
            $effects[] = ['event_id' => $event->reference->id, 'raw_loss_bps' => $damage['loss_bps'], 'loss_bps' => $loss];
            $total = min(10000, $total + $loss);
        }
        return ['loss_bps' => $total, 'effects' => $effects];
    }
}
