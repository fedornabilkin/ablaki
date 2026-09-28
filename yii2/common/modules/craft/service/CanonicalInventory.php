<?php
namespace common\modules\craft\service;

use yii\db\Expression;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** The only storage_v2 inventory writer. Caller holds catalog/owner/registry locks. */
class CanonicalInventory
{
    private $s;
    private $db;
    private $equipment;
    public function __construct(CraftStorage $store) { $this->s = $store; $this->db = $store->db; $this->equipment = new EquipmentInstances($this->db); }
    private function transaction(): void { if (!$this->db->getTransaction()) throw new \LogicException('Inventory writes require a transaction.'); }
    public function backpack(int $user): ?array { return (new Query())->from('craft_storage')->where(['identity_key' => 'backpack:user:' . $user, 'owner_user_id' => $user, 'status' => 'active'])->one($this->db) ?: null; }
    /** Dedicated lifecycle writer. Generic storage routes cannot access kind=shelter. */
    public function shelter(int $user, array $item, array $target, int $position, ?array $source = null): array
    {
        $this->transaction();
        if (($item['code'] ?? '') !== \common\modules\world\service\ShelterCatalog::CODE || (int)$target['owner_user_id'] !== $user || $target['status'] !== 'active' || !in_array($target['kind'], ['backpack', 'shelter'], true)) throw new \LogicException('Invalid shelter destination.');
        $capacity = $target['kind'] === 'backpack' ? (new CraftInventory($this->s))->capacity($user)['active_slots'] : 1;
        if ($position < 1 || $position > $capacity || (new Query())->from('craft_inventory')->where(['storage_id' => $target['id'], 'slot' => $position])->andWhere(['>', 'item_quantity', 0])->exists($this->db)) throw new ConflictHttpException('Для шалаша нет свободного места.');
        if ($source) {
            if ((int)$source['user_id'] !== $user || (int)$source['item_id'] !== (int)$item['id'] || (int)$source['item_quantity'] !== 1) throw new \LogicException('Invalid shelter source.');
            $units = $this->equipment->units((int)$source['id']);
            if (count($units) !== 1) throw new ConflictHttpException('Экземпляр шалаша требует сверки.');
            $this->write((int)$source['id'], ['storage_id' => $target['id'], 'slot' => $position, 'container_id' => null]);
            $row = array_merge($source, ['storage_id' => $target['id'], 'slot' => $position]);
            $this->touch([(int)$source['storage_id']]);
        } else {
            $row = $this->insert($user, $target, $item, 1, $position);
            $this->equipment->create($row, $item, 1); $units = $this->equipment->units((int)$row['id']);
        }
        (new EquipmentExposure($this->db))->location([(int)$units[0]['id']], $target, $position);
        $this->equipment->move([(int)$units[0]['id']], (int)$row['id']);
        $this->record($user, $row, 1, $source ? (int)$source['storage_id'] : null, (int)$target['id'], 'shelter.lifecycle');
        $this->touch([(int)$target['id']]);
        return ['inventory_id' => (int)$row['id'], 'instance_id' => (int)$units[0]['id']];
    }
    public function rows(int $storage): array
    {
        return (new Query())->from('craft_inventory')->where(['storage_id' => $storage])->andWhere(['>', 'item_quantity', 0])->orderBy(['slot' => SORT_ASC, 'id' => SORT_ASC])->all($this->db);
    }
    public function initialize(int $user): void
    {
        $this->transaction();
        if ((new Query())->from('craft_inventory')->where(['user_id' => $user, 'storage_id' => null])->andWhere(['>', 'item_quantity', 0])->exists($this->db)) throw new ConflictHttpException('Перенос инвентаря ещё не завершён.');
        if (!$this->backpack($user)) $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'backpack:user:' . $user, 'kind' => 'backpack', 'owner_user_id' => $user, 'capacity' => CraftInventory::LIMIT])->execute();
        if (!(new Query())->from('craft_capacity')->where(['user_id' => $user])->exists($this->db)) $this->db->createCommand()->insert('craft_capacity', ['user_id' => $user, 'permanent_slots' => CraftInventory::BASE])->execute();
    }
    public function synchronize(int $user): void
    {
        $this->initialize($user);
        $layout = (new CraftInventory($this->s))->layout($user); $changed = [];
        foreach ($layout as $row) {
            $old = (new Query())->select('slot')->from('craft_inventory')->where(['id' => $row['id']])->scalar($this->db);
            if ((int)$old !== (int)$row['slot']) $changed[] = $row;
        }
        if (!$changed) return;
        $reservations = new ProductionReservations($this->db);
        foreach ($changed as $row) $reservations->assertWrite((int)$row['id'], ['slot' => $row['slot']]);
        // Vacate first: no temporary collision with UNIQUE(storage_id,slot).
        $this->db->createCommand()->update('craft_inventory', ['slot' => null], ['id' => array_column($changed, 'id')])->execute();
        foreach ($changed as $row) $this->write((int)$row['id'], ['slot' => $row['slot']]);
        $this->touch([(int)$this->backpack($user)['id']]);
    }
    private function write(int $id, array $values): void
    {
        (new ProductionReservations($this->db))->assertWrite($id, $values);
        $values['revision'] = new Expression('[[revision]]+1');
        if ($this->db->createCommand()->update('craft_inventory', $values, ['id' => $id])->execute() !== 1) throw new \RuntimeException('Inventory row write failed.');
    }
    private function touch(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        if ($ids) $this->db->createCommand()->update('craft_storage', ['revision' => new Expression('[[revision]]+1')], ['id' => $ids])->execute();
    }
    private function record(int $user, array $row, int $quantity, ?int $source, ?int $destination, string $reason): void
    {
        if (!$this->s->operationId) {
            $this->s->operationId = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $this->s->operationId, 'user_id' => $user, 'type' => 'inventory.legacy', 'created_at' => time()])->execute();
        }
        $this->db->createCommand()->insert('inventory_movement', ['operation_id' => $this->s->operationId, 'inventory_id' => $row['id'], 'item_id' => $row['item_id'], 'source_storage_id' => $source, 'destination_storage_id' => $destination, 'quantity' => $quantity, 'reason' => $reason, 'created_at' => time()])->execute();
    }
    private function insert(int $user, array $storage, array $item, int $quantity, int $position): array
    {
        $row = ['user_id' => $user, 'storage_id' => $storage['id'], 'container_id' => $storage['kind'] === 'chest' ? $storage['container_inventory_id'] : null, 'item_id' => $item['id'], 'item_quantity' => $quantity, 'slot' => $position];
        $this->db->createCommand()->insert('craft_inventory', $row)->execute();
        return ['id' => (int)$this->db->getLastInsertID(), 'revision' => 1] + $row;
    }
    public function innerChest(int $user, int $inventory): array
    {
        $row = (new Query())->from('craft_storage')->where(['container_inventory_id' => $inventory, 'owner_user_id' => $user, 'status' => 'active'])->one($this->db);
        if (!$row) throw new ConflictHttpException('Хранилище сундука недоступно.');
        return (new StorageAccessPolicy($this->db))->storage($user, (int)$row['id']);
    }
    private function createChest(int $user, array $row): void
    {
        $settings = (new CraftInventory($this->s))->settings();
        $this->db->createCommand()->insert('craft_container', ['id' => $row['id'], 'user_id' => $user, 'capacity' => $settings['chest_slots'], 'durability' => $settings['chest_durability'], 'max_durability' => $settings['chest_durability']])->execute();
        $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'chest:item:' . $row['id'], 'kind' => 'chest', 'owner_user_id' => $user, 'container_inventory_id' => $row['id'], 'capacity' => $settings['chest_slots']])->execute();
    }
    public function nonempty(int $inventory): bool
    {
        $storage = (new Query())->select('id')->from('craft_storage')->where(['container_inventory_id' => $inventory])->scalar($this->db);
        return $storage && (new Query())->from('craft_inventory')->where(['storage_id' => $storage])->andWhere(['>', 'item_quantity', 0])->exists($this->db);
    }
    public function change(int $user, array $item, int $delta, ?int $slot = null): void
    {
        $this->transaction(); if (!$delta) return;
        if (($item['code'] ?? '') === \common\modules\world\service\ShelterCatalog::CODE) throw new ConflictHttpException('Используйте действие получения или размещения шалаша.');
        $this->synchronize($user);
        $backpack = $this->backpack($user); $storage = (new StorageAccessPolicy($this->db))->storage($user, (int)$backpack['id'], $delta > 0);
        if ($delta < 0) {
            $needed = -$delta; $reservations = new ProductionReservations($this->db);
            foreach ($this->rows((int)$storage['id']) as $row) {
                if ((int)$row['item_id'] !== (int)$item['id'] || ($slot !== null && (int)$row['id'] !== $slot) || ($slot === null && (int)$row['slot'] > (int)$storage['capacity']) || $this->nonempty((int)$row['id'])) continue;
                $units = $this->equipment->units((int)$row['id']);
                $tracked = $this->equipment->tracked($item);
                if ($tracked && count($units) !== (int)$row['item_quantity']) throw new ConflictHttpException('Экземпляры оборудования требуют сверки.');
                $available = array_values(array_filter($reservations->freeEquipment($units), function ($unit) { return !in_array((int)$unit['id'], $this->s->protectedInstances, true); }));
                $take = min($needed, $tracked ? count($available) : (int)$row['item_quantity'], max(0, (int)$row['item_quantity'] - $reservations->quantity((int)$row['id'])));
                if (!$take) continue;
                $this->equipment->consume(array_map('intval', array_column(array_slice($available, 0, $take), 'id')));
                $left = (int)$row['item_quantity'] - $take;
                $this->write((int)$row['id'], ['item_quantity' => $left, 'item_id' => $left ? $row['item_id'] : null, 'slot' => $left ? $row['slot'] : null]);
                if (!$left && ($item['storage_kind'] ?? '') === 'chest') $this->db->createCommand()->update('craft_storage', ['status' => 'retired', 'revision' => new Expression('[[revision]]+1')], ['container_inventory_id' => $row['id']])->execute();
                $this->record($user, $row, $take, (int)$storage['id'], null, 'consume');
                $needed -= $take;
                if (!$needed) { $this->touch([(int)$storage['id']]); return; }
            }
            throw new ConflictHttpException('Не хватает доступных предметов. Установленные вещи и содержимое сундуков не расходуются из рюкзака.');
        }
        if ($slot !== null) throw new \InvalidArgumentException('Positive slot targeting is unsupported.');
        $stack = ($item['storage_kind'] ?? '') === 'chest' ? 1 : (int)$item['stack_size'];
        if ($stack < 1 || $stack > 10000) throw new ConflictHttpException('Некорректный размер стопки.');
        $occupied = [];
        foreach ($this->rows((int)$storage['id']) as $row) {
            $occupied[(int)$row['slot']] = true;
            if ((int)$row['slot'] > (int)$storage['capacity'] || (int)$row['item_id'] !== (int)$item['id']) continue;
            $add = min($delta, max(0, $stack - (int)$row['item_quantity'])); if (!$add) continue;
            $this->write((int)$row['id'], ['item_quantity' => (int)$row['item_quantity'] + $add]);
            $this->equipment->create($row, $item, $add); $this->record($user, $row, $add, null, (int)$storage['id'], 'grant');
            $delta -= $add; if (!$delta) break;
        }
        for ($position = 1; $position <= (int)$storage['capacity'] && $delta > 0; $position++) {
            if (isset($occupied[$position])) continue;
            $add = min($delta, $stack); $row = $this->insert($user, $storage, $item, $add, $position);
            $this->equipment->create($row, $item, $add);
            if (($item['storage_kind'] ?? '') === 'chest') $this->createChest($user, $row);
            $this->record($user, $row, $add, null, (int)$storage['id'], 'grant'); $delta -= $add;
        }
        if ($delta) throw new ConflictHttpException('Активные слоты рюкзака заполнены.');
        $this->touch([(int)$storage['id']]);
    }
    /** Isolated construction reserve. Public storage routes cannot read or spend this kind. */
    public function reserveConstruction(int $user, array $plan, string $operation): int
    {
        $this->transaction();
        $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'construction:' . $operation, 'kind' => 'construction', 'owner_user_id' => $user, 'capacity' => max(1, count($plan))])->execute();
        $id = (int)$this->db->getLastInsertID();
        $storage = (new Query())->from('craft_storage')->where(['id' => $id])->one($this->db); $position = 0;
        foreach ($plan as $take) {
            $selection = $this->inspectOrderDelivery($user, $take['inventory_id'], $take['item_id'], $take['quantity']);
            $row = $selection['row']; $left = (int)$row['item_quantity'] - $take['quantity'];
            $this->write((int)$row['id'], ['item_quantity' => $left, 'item_id' => $left ? $row['item_id'] : null, 'slot' => $left ? $row['slot'] : null]);
            $this->insert($user, $storage, ['id' => $take['item_id']], $take['quantity'], ++$position);
            $this->record($user, $row, $take['quantity'], (int)$row['storage_id'], $id, 'construction.reserve');
            $this->touch([(int)$row['storage_id']]);
        }
        $this->touch([$id]);
        return $id;
    }
    public function resolveConstruction(int $user, int $id, bool $refund): array
    {
        $this->transaction();
        $storage = (new Query())->from('craft_storage')->where(['id' => $id, 'owner_user_id' => $user, 'kind' => 'construction', 'status' => 'active'])->one($this->db);
        if (!$storage) throw new \LogicException('Construction reserve unavailable.');
        $changed = [$id];
        if ($refund) {
            $return = $this->constructionReturnPlan($user, $id); $target = $return['target'];
            foreach ($return['grants'] as $grant) {
                $item = $return['items'][$grant['item_id']];
                if ($grant['inventory_id'] === null) $row = $this->insert($user, $target, $item, $grant['quantity'], $grant['position']);
                else {
                    $row = (new Query())->from('craft_inventory')->where(['id' => $grant['inventory_id'], 'storage_id' => $target['id'], 'user_id' => $user, 'item_id' => $item['id']])->one($this->db);
                    if (!$row) throw new \LogicException('Construction refund destination changed.');
                    $this->write((int)$row['id'], ['item_quantity' => (int)$row['item_quantity'] + $grant['quantity']]);
                }
                $this->record($user, $row, $grant['quantity'], $id, (int)$target['id'], 'construction.refund');
            }
            $changed[] = (int)$target['id']; $this->touch([(int)$target['id']]);
        }
        foreach ($this->rows($id) as $row) {
            if ($this->equipment->units((int)$row['id'])) throw new \LogicException('Equipment in material reserve.');
            $this->write((int)$row['id'], ['item_quantity' => 0, 'item_id' => null, 'slot' => null]);
            if (!$refund) $this->record($user, $row, (int)$row['item_quantity'], $id, null, 'construction.consume');
        }
        $this->db->createCommand()->update('craft_storage', ['status' => 'retired'], ['id' => $id])->execute();
        $this->touch([$id]);
        return array_values(array_unique($changed));
    }
    /** Simulate the whole refund before moving anything. Cancellation must not create free overflow storage. */
    public function constructionReturnPlan(int $user, int $source): array
    {
        $reserve = (new Query())->from('craft_storage')->where(['id' => $source, 'kind' => 'construction', 'owner_user_id' => $user, 'status' => 'active'])->one($this->db);
        $backpack = $this->backpack($user);
        if (!$reserve || !$backpack) throw new ConflictHttpException('Хранилище материалов недоступно.');
        $target = (new StorageAccessPolicy($this->db))->storage($user, (int)$backpack['id'], true);
        $required = []; $items = []; $grants = []; $positions = [];
        foreach ($this->rows($source) as $row) $required[(int)$row['item_id']] = ($required[(int)$row['item_id']] ?? 0) + (int)$row['item_quantity'];
        foreach ($this->rows((int)$target['id']) as $row) $positions[(int)$row['slot']] = $row;
        ksort($required);
        foreach ($required as $id => $quantity) {
            $item = (new Query())->from('craft_item')->where(['id' => $id])->one($this->db);
            if (!$item || $this->equipment->tracked($item) || ($item['storage_kind'] ?? 'none') !== 'none' || (int)$item['stack_size'] < 1 || (int)$item['stack_size'] > 10000) throw new ConflictHttpException('Каталог материала изменился. Требуется восстановить его условия перед отменой стройки.');
            $items[$id] = $item;
            // Fill existing stacks before occupying a free slot.
            for ($pass = 0; $pass < 2 && $quantity; $pass++) for ($slot = 1; $slot <= (int)$target['capacity'] && $quantity; $slot++) {
                $row = $positions[$slot] ?? null;
                if (($pass === 0 && (!$row || (int)$row['item_id'] !== $id)) || ($pass === 1 && $row)) continue;
                $take = min($quantity, max(0, (int)$item['stack_size'] - ($row ? (int)$row['item_quantity'] : 0)));
                if (!$take) continue;
                $grants[] = ['inventory_id' => $row ? (int)$row['id'] : null, 'position' => $slot, 'item_id' => $id, 'quantity' => $take];
                $positions[$slot] = ['id' => $row['id'] ?? null, 'item_id' => $id, 'item_quantity' => ($row ? (int)$row['item_quantity'] : 0) + $take];
                $quantity -= $take;
            }
            if ($quantity) throw new ConflictHttpException('Освободите место в рюкзаке для возврата всех материалов. Стройка и резервы сохранены.');
        }
        if (count($grants) > 2000) throw new ConflictHttpException('Для возврата требуется слишком много ячеек. Объедините материалы в рюкзаке.');
        return compact('target', 'grants', 'items');
    }
    /** Explicit selected backpack stack, ordinary gathered goods only. No equipment/chest consumption. */
    public function inspectOrderDelivery(int $user, int $inventory, int $item, int $quantity): array
    {
        $row = (new Query())->from('craft_inventory')->where(['id' => $inventory, 'user_id' => $user, 'item_id' => $item])->one($this->db);
        $definition = (new Query())->from('craft_item')->where(['id' => $item, 'active' => 1])->one($this->db);
        if (!$row || !$definition || $quantity < 1 || $quantity > 10000 || (int)$row['item_quantity'] < $quantity) throw new ConflictHttpException('В выбранной ячейке недостаточно предметов.');
        if (($definition['storage_kind'] ?? 'none') !== 'none' || $definition['kind'] !== 'material' || (int)$definition['gather_quantity'] < 1 || $this->equipment->tracked($definition) || $this->equipment->units($inventory)) throw new ConflictHttpException('Для начального заказа требуется обычное сырьё.');
        $storage = (new StorageAccessPolicy($this->db))->storage($user, (int)$row['storage_id']);
        if ($storage['kind'] !== 'backpack' || (int)$row['slot'] < 1 || (int)$row['slot'] > (int)$storage['capacity']) throw new ConflictHttpException('Перенесите сырьё в доступную ячейку рюкзака.');
        return ['row' => $row, 'storage' => $storage];
    }
    public function deliverOrder(int $user, int $inventory, int $item, int $quantity): int
    {
        return $this->consumeRawMaterial($user, $inventory, $item, $quantity, 'order.consumed');
    }
    public function consumeBuildingRepair(int $user, int $inventory, int $item, int $quantity): int
    {
        return $this->consumeRawMaterial($user, $inventory, $item, $quantity, 'building.repair');
    }
    private function consumeRawMaterial(int $user, int $inventory, int $item, int $quantity, string $reason): int
    {
        $this->transaction(); $selection = $this->inspectOrderDelivery($user, $inventory, $item, $quantity); $row = $selection['row'];
        $left = (int)$row['item_quantity'] - $quantity;
        $this->write($inventory, ['item_quantity' => $left, 'item_id' => $left ? $item : null, 'slot' => $left ? $row['slot'] : null]);
        $this->record($user, $row, $quantity, (int)$row['storage_id'], null, $reason);
        $this->touch([(int)$row['storage_id']]);
        return (int)$row['storage_id'];
    }
    /** Read-only, deterministic allocation. Storage access and location are checked by the workspace. */
    public function planCraft(array $sources, array $target, array $required, array $items, array $output, int $quantity): array
    {
        foreach (array_merge([$output], array_intersect_key($items, $required)) as $definition) if (($definition['code'] ?? '') === \common\modules\world\service\ShelterCatalog::CODE) throw new ConflictHttpException('Шалаш не участвует в рецептах.');
        $consumed = []; $materials = []; $blocked = []; $reservations = new ProductionReservations($this->db);
        foreach (array_merge($sources, [$target]) as $storage) if ($storage['kind'] === 'chest') $blocked[(int)$storage['container_inventory_id']] = true;
        foreach ($required as $itemId => $needed) {
            $have = 0; $remaining = $needed;
            foreach ($sources as $storage) foreach ($this->rows((int)$storage['id']) as $row) {
                if ((int)$row['item_id'] !== (int)$itemId || (int)$row['slot'] > (int)$storage['capacity'] || isset($blocked[(int)$row['id']]) || $this->nonempty((int)$row['id'])) continue;
                $units = $this->equipment->units((int)$row['id']); $tracked = $this->equipment->tracked($items[$itemId]);
                if ($tracked && count($units) !== (int)$row['item_quantity']) throw new ConflictHttpException('Экземпляры оборудования требуют сверки.');
                $units = array_values(array_filter($reservations->freeEquipment($units), function ($unit) { return !in_array((int)$unit['id'], $this->s->protectedInstances, true); }));
                $available = min($tracked ? count($units) : (int)$row['item_quantity'], max(0, (int)$row['item_quantity'] - $reservations->quantity((int)$row['id']))); $have += $available;
                $take = min($remaining, $available); if (!$take) continue;
                $consumed[] = ['inventory_id' => (int)$row['id'], 'storage_id' => (int)$storage['id'], 'item_id' => (int)$itemId, 'quantity' => $take,
                    'instance_ids' => array_map('intval', array_column(array_slice($units, 0, $take), 'id'))];
                $remaining -= $take;
            }
            $materials[] = ['item_id' => (int)$itemId, 'name' => trim($items[$itemId]['name']), 'quantity' => $needed, 'have' => $have, 'available' => $remaining === 0];
        }
        $remainingRows = [];
        foreach ($this->rows((int)$target['id']) as $row) $remainingRows[(int)$row['id']] = $row;
        foreach ($consumed as $take) if (isset($remainingRows[$take['inventory_id']])) $remainingRows[$take['inventory_id']]['item_quantity'] -= $take['quantity'];
        $stack = ($output['storage_kind'] ?? '') === 'chest' ? 1 : (int)$output['stack_size'];
        if ($stack < 1 || $stack > 10000) throw new ConflictHttpException('Некорректный размер стопки.');
        $canStore = !(($output['storage_kind'] ?? '') === 'chest' && $target['kind'] === 'chest');
        $left = $quantity; $grants = []; $occupied = [];
        foreach ($remainingRows as $row) {
            if ((int)$row['item_quantity'] < 1) continue;
            $occupied[(int)$row['slot']] = true;
            if (!$canStore || (int)$row['slot'] > (int)$target['capacity'] || (int)$row['item_id'] !== (int)$output['id']) continue;
            $add = min($left, max(0, $stack - (int)$row['item_quantity'])); if (!$add) continue;
            $grants[] = ['inventory_id' => (int)$row['id'], 'position' => (int)$row['slot'], 'quantity' => $add]; $left -= $add;
        }
        for ($position = 1; $canStore && $position <= (int)$target['capacity'] && $left; $position++) {
            if (isset($occupied[$position])) continue;
            $add = min($left, $stack); $grants[] = ['inventory_id' => null, 'position' => $position, 'quantity' => $add]; $left -= $add;
        }
        if (count($consumed) + count($grants) > 2000) throw new ConflictHttpException('Слишком много ячеек для одной операции. Уменьшите количество изготовлений.');
        return ['materials' => $materials, 'consume' => $consumed, 'grant' => $grants, 'output_fits' => $left === 0, 'output_missing_quantity' => $left];
    }
    /** Apply a freshly recomputed plan under the command bus locks, never a client-supplied plan. */
    public function applyCraft(int $user, array $plan, array $target, array $items, array $output, string $reason = 'craft'): void
    {
        $this->transaction();
        if (!$plan['output_fits']) throw new ConflictHttpException('Недостаточно места для результата.');
        foreach ($plan['materials'] as $material) if (!$material['available']) throw new ConflictHttpException('Не хватает материалов.');
        $changed = [(int)$target['id']];
        foreach ($plan['consume'] as $take) {
            $row = (new Query())->from('craft_inventory')->where(['id' => $take['inventory_id'], 'user_id' => $user, 'storage_id' => $take['storage_id'], 'item_id' => $take['item_id']])->one($this->db);
            if (!$row || (int)$row['item_quantity'] < $take['quantity']) throw new ConflictHttpException('Материалы изменились.');
            $left = (int)$row['item_quantity'] - $take['quantity'];
            $this->equipment->consume($take['instance_ids']);
            $this->write((int)$row['id'], ['item_quantity' => $left, 'item_id' => $left ? $row['item_id'] : null, 'slot' => $left ? $row['slot'] : null]);
            if (!$left && ($items[$take['item_id']]['storage_kind'] ?? '') === 'chest') $this->db->createCommand()->update('craft_storage', ['status' => 'retired', 'revision' => new Expression('[[revision]]+1')], ['container_inventory_id' => $row['id']])->execute();
            $this->record($user, $row, $take['quantity'], $take['storage_id'], null, $reason . '.consume'); $changed[] = $take['storage_id'];
        }
        foreach ($plan['grant'] as $grant) {
            if ($grant['inventory_id'] === null) {
                $row = $this->insert($user, $target, $output, $grant['quantity'], $grant['position']);
                if (($output['storage_kind'] ?? '') === 'chest') $this->createChest($user, $row);
            } else {
                $row = (new Query())->from('craft_inventory')->where(['id' => $grant['inventory_id'], 'user_id' => $user, 'storage_id' => $target['id'], 'item_id' => $output['id']])->one($this->db);
                if (!$row) throw new ConflictHttpException('Место выдачи изменилось.');
                $this->write((int)$row['id'], ['item_quantity' => (int)$row['item_quantity'] + $grant['quantity']]);
            }
            $this->equipment->create($row, $output, $grant['quantity']);
            $this->record($user, $row, $grant['quantity'], null, (int)$target['id'], $reason . '.output');
        }
        // One deposit operation wears the receiving chest once, including a multi-batch recipe.
        if ($target['kind'] === 'chest') {
            $container = (new Query())->from('craft_container')->where(['id' => $target['container_inventory_id']])->one($this->db);
            $wear = (new CraftInventory($this->s))->settings()['chest_wear'];
            if ($wear) $this->db->createCommand()->update('craft_container', ['durability' => max(0, (int)$container['durability'] - $wear)], ['id' => $container['id']])->execute();
        }
        $this->touch($changed);
    }
    /** Preview and commit share this validation; commit repeats it under the write locks. */
    public function inspectTransfer(int $user, int $inventory, int $destination, int $position, int $quantity, ?int $instance = null, bool $partial = false): array
    {
        $row = (new Query())->from('craft_inventory')->where(['id' => $inventory, 'user_id' => $user])->one($this->db);
        if (!$row || !$row['item_id'] || $quantity < 1 || $quantity > 10000 || (int)$row['item_quantity'] < $quantity) throw new ConflictHttpException('Предмет в исходном слоте изменился.');
        $policy = new StorageAccessPolicy($this->db);
        $source = $policy->storage($user, (int)$row['storage_id']); $target = $policy->storage($user, $destination, true);
        if ($position < 1 || $position > (int)$target['capacity']) throw new ConflictHttpException('Целевой слот недоступен.');
        $item = (new Query())->from('craft_item')->where(['id' => $row['item_id']])->one($this->db);
        $chest = ($item['storage_kind'] ?? '') === 'chest';
        if ($chest && $target['kind'] === 'chest') throw new ConflictHttpException('Сундук нельзя помещать в другой сундук.');
        $occupied = (new Query())->from('craft_inventory')->where(['storage_id' => $destination, 'slot' => $position])->andWhere(['>', 'item_quantity', 0])->one($this->db);
        if ($occupied && (int)$occupied['id'] === $inventory) throw new ConflictHttpException('Предмет уже в этом слоте.');
        if ($occupied && (int)$occupied['item_id'] !== (int)$item['id']) throw new ConflictHttpException('Слот занят другим предметом.');
        $stack = $chest ? 1 : (int)$item['stack_size'];
        if ($target['kind'] === 'placement') {
            $slot = (new Query())->from('world_slot')->where(['storage_id' => $destination, 'position' => $position, 'status' => 'active'])->one($this->db);
            if (!$slot) throw new ConflictHttpException('Предмет не подходит для этого места.');
            (new EquipmentPlacementPolicy($this->db))->assertAllowed($item, $target, $slot);
            if ($quantity !== 1 || $occupied) throw new ConflictHttpException('В монтажном слоте размещается одна вещь.');
            $stack = 1;
        }
        $room = max(0, $stack - (int)($occupied['item_quantity'] ?? 0));
        if (!$room || (!$partial && $quantity > $room)) throw new ConflictHttpException('В целевом слоте недостаточно места.');
        $quantity = min($quantity, $room);
        $reservations = new ProductionReservations($this->db);
        if ($quantity > (int)$row['item_quantity'] - $reservations->quantity($inventory)) throw new ConflictHttpException('Предметы зарезервированы производством.');
        $units = $this->equipment->units($inventory); $tracked = $this->equipment->tracked($item);
        if ($tracked && count($units) !== (int)$row['item_quantity']) throw new ConflictHttpException('Экземпляры оборудования требуют сверки.');
        $units = array_values(array_filter($reservations->freeEquipment($units), function ($unit) use ($instance) { return !in_array((int)$unit['id'], $this->s->protectedInstances, true) && ($instance === null || (int)$unit['id'] === $instance); }));
        if (($tracked && count($units) < $quantity) || ($instance !== null && (!$tracked || $quantity !== 1))) throw new ConflictHttpException('Выбранный экземпляр недоступен.');
        return ['row' => $row, 'source' => $source, 'target' => $target, 'item' => $item, 'occupied' => $occupied ?: null, 'quantity' => $quantity, 'position' => $position, 'unit_ids' => array_map('intval', array_column(array_slice($units, 0, $quantity), 'id'))];
    }
    public function transfer(int $user, int $inventory, int $destination, int $position, int $quantity, ?int $instance = null, bool $partial = false): array
    {
        $this->transaction(); $p = $this->inspectTransfer($user, $inventory, $destination, $position, $quantity, $instance, $partial);
        $row = $p['row']; $target = $p['target']; $source = $p['source']; $item = $p['item']; $quantity = $p['quantity']; $out = $p['occupied'];
        (new EquipmentExposure($this->db))->location($p['unit_ids'], $target, $position);
        if (($item['storage_kind'] ?? '') === 'chest') {
            $this->write($inventory, ['storage_id' => $destination, 'slot' => $position, 'container_id' => null]); $out = $row;
        } else {
            $left = (int)$row['item_quantity'] - $quantity;
            $this->write($inventory, ['item_quantity' => $left, 'item_id' => $left ? $row['item_id'] : null, 'slot' => $left ? $row['slot'] : null]);
            if ($out) $this->write((int)$out['id'], ['item_quantity' => (int)$out['item_quantity'] + $quantity]);
            else $out = $this->insert($user, $target, $item, $quantity, $position);
            $this->equipment->move($p['unit_ids'], (int)$out['id']);
        }
        if ($target['kind'] === 'chest' && $source['id'] !== $target['id']) {
            $container = (new Query())->from('craft_container')->where(['id' => $target['container_inventory_id']])->one($this->db);
            $wear = (new CraftInventory($this->s))->settings()['chest_wear'];
            if ($wear) $this->db->createCommand()->update('craft_container', ['durability' => max(0, (int)$container['durability'] - $wear)], ['id' => $container['id']])->execute();
        }
        $this->record($user, $out, $quantity, (int)$source['id'], $destination, 'transfer');
        $this->touch([(int)$source['id'], $destination]);
        return ['quantity' => $quantity, 'inventory_id' => (int)$out['id'], 'instance_ids' => $p['unit_ids'], 'changed_storage_ids' => array_values(array_unique([(int)$source['id'], $destination]))];
    }
    /** StorageRecovery validates the authoritative loss of access under the same locks. */
    public function recover(int $user, array $row, array $source): array
    {
        $this->transaction();
        if ((int)$row['user_id'] !== $user || (int)$source['owner_user_id'] !== $user || !in_array($source['kind'], ['placement', 'stockpile'], true) || (int)$row['storage_id'] !== (int)$source['id']) throw new ConflictHttpException('Источник восстановления недоступен.');
        $where = ['identity_key' => 'recovery:user:' . $user, 'owner_user_id' => $user, 'kind' => 'recovery', 'status' => 'active'];
        $target = (new Query())->from('craft_storage')->where($where)->one($this->db);
        if (!$target) {
            $this->db->createCommand()->insert('craft_storage', $where + ['capacity' => 10000])->execute();
            $target = (new Query())->from('craft_storage')->where(['id' => $this->db->getLastInsertID()])->one($this->db);
        }
        $occupied = array_flip(array_map('intval', array_column($this->rows((int)$target['id']), 'slot'))); $position = 1;
        while (isset($occupied[$position]) && $position <= (int)$target['capacity']) $position++;
        if ($position > (int)$target['capacity']) throw new ConflictHttpException('Сначала заберите часть вещей из восстановления. Исходная вещь сохранена.');
        $units = $this->equipment->units((int)$row['id']);
        (new EquipmentExposure($this->db))->location(array_map('intval', array_column($units, 'id')), $target, $position);
        // Preserve the original row and chest identity; contents remain in the same inner storage.
        $this->write((int)$row['id'], ['storage_id' => (int)$target['id'], 'container_id' => null, 'slot' => $position]);
        $this->record($user, $row, (int)$row['item_quantity'], (int)$source['id'], (int)$target['id'], 'recovery');
        $this->touch([(int)$source['id'], (int)$target['id']]);
        return ['recovery_storage_id' => (int)$target['id'], 'changed_storage_ids' => [(int)$source['id'], (int)$target['id']]];
    }
    public function legacyTransfer(int $user, int $inventory, int $container, int $position, int $quantity): int
    {
        $source = (new Query())->from('craft_inventory')->where(['id' => $inventory, 'user_id' => $user])->one($this->db);
        if (!$source) throw new ConflictHttpException('Предмет недоступен.');
        $outer = (new StorageAccessPolicy($this->db))->storage($user, (int)$source['storage_id']);
        if (!in_array($outer['kind'], ['backpack', 'chest'], true)) throw new ConflictHttpException('Для установленной вещи используйте страницу её размещения.');
        // Legacy chest actions only address chests currently in the backpack.
        if ($outer['kind'] === 'chest') $this->requireBackpackChest($user, (int)$outer['container_inventory_id']);
        if ($container) { $this->requireBackpackChest($user, $container); $target = $this->innerChest($user, $container); }
        else $target = $this->backpack($user);
        if (!$target) throw new ConflictHttpException('Рюкзак недоступен.');
        return $this->transfer($user, $inventory, (int)$target['id'], $position, $quantity, null, true)['quantity'];
    }
    public function requireBackpackChest(int $user, int $id): void
    {
        $backpack = $this->backpack($user);
        if (!$backpack || !(new Query())->from('craft_inventory')->where(['id' => $id, 'user_id' => $user, 'storage_id' => $backpack['id'], 'item_quantity' => 1])->exists($this->db)) throw new ConflictHttpException('Выберите сундук из рюкзака.');
    }
}
