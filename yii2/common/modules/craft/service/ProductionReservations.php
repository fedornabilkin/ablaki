<?php
namespace common\modules\craft\service;

use common\services\game\GameError;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Query;

/** All callers use the catalog -> owner -> registry lock order. Reservations are not stock. */
class ProductionReservations
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function installed(): bool { return $this->db->schema->getTableSchema('inventory_reservation') !== null; }
    public function quantity(int $inventory): int
    {
        return $this->installed() ? (int)(new Query())->from('inventory_reservation')->where(['inventory_id' => $inventory, 'released_at' => null])->sum('quantity', $this->db) : 0;
    }
    public function assertWrite(int $inventory, array $values): void
    {
        $reserved = $this->quantity($inventory);
        if (!$reserved) return;
        $row = (new Query())->from('craft_inventory')->where(['id' => $inventory])->one($this->db);
        foreach (['item_id', 'storage_id', 'user_id', 'container_id', 'slot'] as $field) {
            if (array_key_exists($field, $values) && (string)$values[$field] !== (string)$row[$field]) throw new GameError('INVENTORY_RESERVED', 'Предмет зарезервирован производством.');
        }
        if (array_key_exists('item_quantity', $values) && (!is_numeric($values['item_quantity']) || (int)$values['item_quantity'] < $reserved)) throw new GameError('INVENTORY_RESERVED', 'Недостаточно свободных материалов: часть используется производством.');
    }
    public function assertEquipment(array $ids): void
    {
        if ($ids && $this->installed() && (new Query())->from('equipment_reservation_guard')->where(['instance_id' => $ids])->exists($this->db)) throw new GameError('EQUIPMENT_RESERVED', 'Инструмент или станция заняты производством.');
    }
    public function freeEquipment(array $units): array
    {
        if (!$units || !$this->installed()) return $units;
        $reserved = array_flip((new Query())->select('instance_id')->from('equipment_reservation_guard')->where(['instance_id' => array_column($units, 'id')])->column($this->db));
        return array_values(array_filter($units, static function ($unit) use ($reserved) { return !isset($reserved[$unit['id']]); }));
    }
    public function reserve(array $order, array $materials, array $instances, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Reservation requires a command transaction.');
        ksort($materials, SORT_NUMERIC); sort($instances, SORT_NUMERIC);
        if (count($instances) !== count(array_unique($instances))) throw new \LogicException('Duplicate equipment reservation.');
        foreach ($materials as $id => $quantity) {
            $row = (new Locks($this->db))->row('craft_inventory', ['id' => (int)$id]);
            if (!$row || !is_int($quantity) || $quantity < 1 || (int)$row['user_id'] !== (int)$order['owner_user_id'] || $quantity > (int)$row['item_quantity'] - $this->quantity((int)$id)) throw new GameError('RESERVATION_UNAVAILABLE', 'Материалы уже использованы или зарезервированы.');
            $this->db->createCommand()->insert('inventory_reservation', ['order_id' => $order['id'], 'inventory_id' => $id, 'operation_id' => $operation, 'quantity' => $quantity, 'created_at' => time()])->execute();
        }
        foreach ($instances as $id) {
            $instance = (new Locks($this->db))->row('craft_equipment_instance', ['id' => $id]);
            $row = $instance ? (new Query())->from('craft_inventory')->where(['id' => $instance['inventory_id']])->one($this->db) : null;
            if (!$row || (int)$row['user_id'] !== (int)$order['owner_user_id'] || $instance['status'] !== 'active' || (int)$instance['durability'] < 1) throw new GameError('EQUIPMENT_UNAVAILABLE', 'Экземпляр недоступен для производства.');
            $this->assertEquipment([$id]);
            $this->db->createCommand()->insert('equipment_reservation', ['order_id' => $order['id'], 'instance_id' => $id, 'operation_id' => $operation, 'created_at' => time()])->execute();
            $this->db->createCommand()->insert('equipment_reservation_guard', ['instance_id' => $id, 'reservation_id' => (int)$this->db->getLastInsertID()])->execute();
        }
    }
    public function release(int $order): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Reservation release requires a command transaction.');
        $ids = (new Query())->select('id')->from('equipment_reservation')->where(['order_id' => $order, 'released_at' => null])->column($this->db);
        if ($ids) {
            if ($this->db->createCommand()->delete('equipment_reservation_guard', ['reservation_id' => $ids])->execute() !== count($ids)) throw new \RuntimeException('Equipment reservation guard mismatch.');
            $this->db->createCommand()->update('equipment_reservation', ['released_at' => time()], ['id' => $ids])->execute();
        }
        $this->db->createCommand()->update('inventory_reservation', ['released_at' => time()], ['order_id' => $order, 'released_at' => null])->execute();
    }
}
