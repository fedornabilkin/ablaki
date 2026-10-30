<?php
namespace common\modules\world\modules\craft\models\domain;

use common\modules\world\models\domain\EconomyRuntime;

/** Persistence adapter shared by inventory movements and completed recipes. */
final class CraftOperationJournal
{
    public function __construct(private readonly CraftStorage $store)
    {
    }

    public function operation(int $user, string $type): string
    {
        if (!$this->store->db->getTransaction()) {
            throw new \LogicException('Craft journal requires the operation transaction.');
        }
        if ($this->store->operationId) {
            return $this->store->operationId;
        }
        $id = bin2hex(random_bytes(16));
        $written = $this->store->db->createCommand()->insert('game_operation', [
            'id' => $id,
            'user_id' => $user,
            'type' => $type,
            'created_at' => time(),
        ] + EconomyRuntime::operationContext($this->store->db, [
            'node_id' => $this->store->workspaceNodeId,
        ]))->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Craft operation write failed.');
        }
        $this->store->operationId = $id;
        return $id;
    }

    public function movement(int $user, array $row, int $quantity, ?int $source, ?int $destination, string $reason): void
    {
        $written = $this->store->db->createCommand()->insert('inventory_movement', [
            'operation_id' => $this->operation($user, 'inventory.legacy'),
            'inventory_id' => $row['id'],
            'item_id' => $row['item_id'],
            'source_storage_id' => $source,
            'destination_storage_id' => $destination,
            'quantity' => $quantity,
            'reason' => $reason,
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Inventory movement write failed.');
        }
    }
}
