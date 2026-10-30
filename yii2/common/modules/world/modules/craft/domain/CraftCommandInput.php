<?php
namespace common\modules\world\modules\craft\domain;

/** Validated legacy command. Fingerprints deliberately retain the deployed positional format. */
final class CraftCommandInput
{
    private function __construct(
        public readonly string $action,
        public readonly int $itemId,
        public readonly int $quantity,
        public readonly ?int $slotId,
        public readonly ?int $targetSlotId,
        public readonly int $containerId,
        public readonly int $position,
        public readonly ?int $unitPrice,
        public readonly ?int $totalPrice,
        public readonly string $fingerprint
    ) {
    }

    public static function parse(int $user, string $key, string $action, array $payload): self
    {
        if ($user < 1 || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) {
            throw new \InvalidArgumentException('Нужен корректный ключ команды.');
        }
        if (!in_array($action, ['craft', 'starter', 'gather', 'discard', 'use', 'merge', 'transfer', 'buy_slots', 'repair'], true)) {
            throw new \InvalidArgumentException('Неизвестная операция.');
        }
        $quantity = array_key_exists('quantity', $payload) ? $payload['quantity'] : 1;
        $id = $payload['id'] ?? 0;
        $maximum = in_array($action, ['use', 'discard', 'transfer'], true) ? 10000 : 100;
        if (!is_int($quantity) || $quantity < 1 || $quantity > $maximum || !is_int($id) || $id < 0) {
            throw new \InvalidArgumentException('Количество должно быть целым от 1 до ' . $maximum . '.');
        }
        $slot = $payload['slot_id'] ?? null;
        if ($slot !== null && (!is_int($slot) || $slot < 1 || !in_array($action, ['use', 'discard', 'merge', 'transfer', 'repair'], true))) {
            throw new \InvalidArgumentException('Некорректный слот инвентаря.');
        }
        if (in_array($action, ['starter', 'gather', 'repair'], true) && $quantity !== 1) {
            throw new \InvalidArgumentException('Для этой операции количество должно быть равно 1.');
        }
        if ($action === 'repair' && $slot === null) {
            throw new \InvalidArgumentException('Выберите сундук для ремонта.');
        }
        $target = $payload['target_slot_id'] ?? null;
        if (($target !== null && ($action !== 'merge' || !is_int($target) || $target < 1))
            || ($action === 'merge' && ($slot === null || $target === null || $slot === $target || $quantity !== 1))) {
            throw new \InvalidArgumentException('Выберите два разных слота для объединения.');
        }

        $identity = [$action, $id, $quantity];
        if ($slot !== null) {
            $identity[] = $slot;
        }
        if ($target !== null) {
            $identity[] = $target;
        }
        $container = $payload['container_id'] ?? 0;
        $position = $payload['position'] ?? 0;
        $price = $payload['unit_price'] ?? null;
        $total = $payload['total_price'] ?? null;
        if ($action === 'transfer') {
            if ($slot === null || !is_int($container) || $container < 0 || !is_int($position) || $position < 1 || $position > 100) {
                throw new \InvalidArgumentException('Выберите исходный предмет и целевой слот.');
            }
            $identity[] = ['container_id' => $container, 'position' => $position];
        } elseif (isset($payload['container_id']) || isset($payload['position'])) {
            throw new \InvalidArgumentException('Целевой слот не поддерживается этой операцией.');
        }
        if ($action === 'buy_slots') {
            if (!is_int($price) || $price < 0) {
                throw new \InvalidArgumentException('Подтвердите цену слота.');
            }
            $identity[] = ['unit_price' => $price];
            if (array_key_exists('total_price', $payload)) {
                if (!is_int($total) || $total < 0) {
                    throw new \InvalidArgumentException('Подтвердите полную стоимость слотов.');
                }
                $identity[] = ['total_price' => $total];
            }
        } elseif ($price !== null) {
            throw new \InvalidArgumentException('Цена не поддерживается этой операцией.');
        }
        if ($action !== 'buy_slots' && array_key_exists('total_price', $payload)) {
            throw new \InvalidArgumentException('Стоимость не поддерживается этой операцией.');
        }

        return new self($action, $id, $quantity, $slot, $target, $container, $position, $price, $total, hash('sha256', json_encode($identity)));
    }
}
