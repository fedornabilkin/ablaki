<?php
namespace common\modules\world\domain\events;

use common\modules\world\domain\characteristics\Characteristics;
use common\modules\world\domain\characteristics\CharacteristicSet;

final class CropDamageRule implements EventRule
{
    public function key(): string
    {
        return 'crop_damage';
    }

    public function error(mixed $configuration): ?string
    {
        if (!is_array($configuration) || ($configuration['code'] ?? null) !== 'light_crop_damage'
            || !is_int($configuration['loss_bps'] ?? null) || $configuration['loss_bps'] < 1 || $configuration['loss_bps'] > 2500) {
            return 'Лёгкий урон урожаю: код light_crop_damage и loss_bps от 1 до 2500.';
        }
        return null;
    }

    public function characteristics(array $configuration): Characteristics
    {
        if ($this->error($configuration) !== null) {
            throw new \InvalidArgumentException('Invalid crop damage configuration.');
        }
        return new CharacteristicSet(['crop.damage' => [
            'code' => $configuration['code'], 'loss_bps' => $configuration['loss_bps'],
        ]]);
    }

    public function loss(array $damage, ?array $protection): int
    {
        $loss = $damage['loss_bps'];
        if ($protection && $protection['code'] === $damage['code'] && $loss <= $protection['max_event_loss_bps']) {
            $loss = intdiv($loss * (10000 - $protection['loss_reduction_bps']), 10000);
        }
        return $loss;
    }
}
