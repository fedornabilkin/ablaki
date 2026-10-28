<?php
namespace common\modules\world\modules\craft\models\domain;

use common\modules\world\models\domain\WorldFlags;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

class WorldChestRepair
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function context(int $user, array $payload): array
    {
        $this->flags->requireFlag('storage_v2');
        $inner = (new StorageAccessPolicy($this->db))->storage($user, $payload['storage_id']);
        if ($inner['kind'] !== 'chest' || (int)$inner['container_inventory_id'] !== $payload['container_inventory_id']) throw new GameError('CHEST_CHANGED', 'Сундук изменился.');
        $row = (new Query())->from('craft_inventory')->where(['id' => $payload['container_inventory_id'], 'user_id' => $user])->one($this->db);
        $outer = (new StorageAccessPolicy($this->db))->storage($user, (int)$row['storage_id']);
        $store = new CraftStorage($this->db); $store->allowPlacedChests = true;
        if ($outer['node_id'] !== null) $store->workspaceNodeId = (int)$outer['node_id'];
        $quote = (new ChestRepair($store))->quote($user, (int)$row['id']);
        return ['inner' => $inner, 'outer' => $outer, 'row' => $row, 'store' => $store, 'repair' => $quote];
    }
    private function equipment(array $repair): array
    {
        $ids = [];
        foreach ($repair['tools'] as $tool) $ids[] = $tool['instance_id'];
        if ($repair['station'] && $repair['station']['item_id'] !== null) $ids[] = $repair['station']['instance_id'];
        $ids = array_values(array_unique($ids)); sort($ids); return $ids;
    }
    public function preview(int $user, array $payload): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.chest.repair', $payload, function () use ($user, $payload) {
            $p = $this->context($user, $payload); $backpack = (new CanonicalInventory($p['store']))->backpack($user);
            $revisions = ['storage:' . $p['inner']['id'] => (int)$p['inner']['revision'], 'storage:' . $p['outer']['id'] => (int)$p['outer']['revision'], 'inventory:' . $p['row']['id'] => (int)$p['row']['revision'],
                'catalog' => (int)(new Query())->select('revision')->from('craft_meta')->where(['id' => 1])->scalar($this->db)];
            if ($backpack) $revisions['storage:' . $backpack['id']] = (int)$backpack['revision'];
            return ['revisions' => $revisions, 'terms' => ['repair' => $p['repair'], 'instance_ids' => $this->equipment($p['repair'])]];
        });
    }
    public function repair(int $user, string $key, array $payload, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.chest.repair', $payload, $quote, $revisions, function (array $input, array $terms, string $operation) use ($user, $bus) {
            $p = $this->context($user, $input);
            if ($p['repair']['reasons']) throw new GameError('REPAIR_UNAVAILABLE', implode(' ', $p['repair']['reasons']));
            if ($this->equipment($p['repair']) !== $terms['instance_ids'] || $p['repair']['materials'] !== $terms['repair']['materials'] || $p['repair']['restore'] !== $terms['repair']['restore']) throw new GameError('REPAIR_CHANGED', 'Условия ремонта изменились. Обновите расчёт.');
            $p['store']->operationId = $operation;
            (new ChestRepair($p['store']))->repair($user, (int)$p['row']['id'], (int)$p['row']['item_id']);
            $backpack = (new CanonicalInventory($p['store']))->backpack($user);
            $result = ['changed_storage_ids' => array_values(array_unique(array_filter([(int)$p['inner']['id'], (int)$p['outer']['id'], $backpack ? (int)$backpack['id'] : null]))),
                'changed_node_ids' => $p['outer']['node_id'] === null ? [] : [(int)$p['outer']['node_id']]];
            $bus->emit($operation, $user, 'chest.repaired', ['inventory_id' => (int)$p['row']['id']]);
            return $result;
        });
    }
}
