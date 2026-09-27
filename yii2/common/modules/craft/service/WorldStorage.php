<?php
namespace common\modules\craft\service;

use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldQuery;
use common\modules\world\service\WorldFlags;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

class WorldStorage
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function available(): void { $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2'); }
    public function list(int $user, ?int $node): array
    {
        $this->available();
        if ($node !== null) {
            $visible = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node);
            if (!$visible['permissions']['manage']) throw new GameError('STORAGE_FORBIDDEN', 'Нет доступа к вещам этого объекта.', 403);
        }
        $base = (new Query())->from('craft_storage')->where(['owner_user_id' => $user, 'status' => 'active'])->andWhere(['or', ['kind' => ['backpack', 'recovery']], ['and', ['node_id' => $node], ['kind' => ['placement', 'stockpile']]]])->orderBy(['id' => SORT_ASC])->limit(100)->all($this->db);
        // Chest identity follows its outer inventory row; contents never move with the chest.
        $ids = array_column($base, 'id');
        $chests = $ids ? (new Query())->select('s.*')->from(['s' => 'craft_storage'])->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[s.container_inventory_id]]')
            ->where(['s.owner_user_id' => $user, 's.status' => 'active', 's.kind' => 'chest', 'i.storage_id' => $ids])->andWhere(['>', 'i.item_quantity', 0])->orderBy(['s.id' => SORT_ASC])->limit(100)->all($this->db) : [];
        $result = [];
        foreach (array_merge($base, $chests) as $row) $result[] = $this->header((new StorageAccessPolicy($this->db))->storage($user, (int)$row['id']));
        return ['items' => $result, 'server_time' => time()];
    }
    private function header(array $row): array
    {
        $labels = ['backpack' => 'Рюкзак', 'chest' => 'Сундук', 'placement' => 'Места размещения', 'recovery' => 'Восстановление', 'stockpile' => 'Склад'];
        return ['id' => (int)$row['id'], 'kind' => $row['kind'], 'name' => $labels[$row['kind']] ?? 'Хранилище', 'node_id' => $row['node_id'] === null ? null : (int)$row['node_id'],
            'container_inventory_id' => $row['container_inventory_id'] === null ? null : (int)$row['container_inventory_id'], 'capacity' => (int)$row['capacity'], 'revision' => (int)$row['revision']];
    }
    public function view(int $user, int $id, int $page = 1): array
    {
        $this->available(); if ($page < 1 || $page > 1000000) throw new GameError('INVALID_PAGINATION', 'Некорректная страница.', 422);
        $storage = (new StorageAccessPolicy($this->db))->storage($user, $id); $query = (new Query())->from('craft_inventory')->where(['storage_id' => $id])->andWhere(['>', 'item_quantity', 0]);
        $total = (int)(clone $query)->count('*', $this->db); $items = [];
        $rows = $query->orderBy(['slot' => SORT_ASC, 'id' => SORT_ASC])->offset(($page - 1) * 100)->limit(100)->all($this->db);
        $definitions = (new Query())->from('craft_item')->where(['id' => array_column($rows, 'item_id')])->indexBy('id')->all($this->db);
        $instances = (new Query())->from('craft_equipment_instance')->where(['inventory_id' => array_column($rows, 'id'), 'status' => 'active'])->orderBy(['id' => SORT_ASC])->all($this->db);
        $byInventory = [];
        foreach ((new EquipmentExposure($this->db))->projectedBatch($instances) as $unit) $byInventory[$unit['inventory_id']][] = $unit;
        $inners = (new Query())->from('craft_storage')->where(['container_inventory_id' => array_column($rows, 'id'), 'status' => 'active'])->indexBy('container_inventory_id')->all($this->db);
        foreach ($rows as $row) {
            $item = $definitions[$row['item_id']];
            $units = [];
            foreach ($byInventory[$row['id']] ?? [] as $unit) {
                $units[] = ['id' => (int)$unit['id'], 'durability' => (int)$unit['durability'], 'max_durability' => (int)$unit['max_durability'], 'exposure_class' => $unit['exposure_class']];
            }
            $inner = $inners[$row['id']]['id'] ?? null;
            $items[] = ['id' => (int)$row['id'], 'item_id' => (int)$row['item_id'], 'name' => trim($item['name']), 'icon' => trim($item['icon']), 'quantity' => (int)$row['item_quantity'], 'position' => (int)$row['slot'], 'revision' => (int)$row['revision'], 'instances' => $units, 'inner_storage_id' => $inner ? (int)$inner : null];
        }
        $slots = [];
        if ($storage['kind'] === 'placement') foreach ((new Query())->from('world_slot')->where(['storage_id' => $id])->orderBy(['position' => SORT_ASC])->limit(100)->all($this->db) as $slot) $slots[] = ['position' => (int)$slot['position'], 'type' => $slot['slot_type'], 'exposure_class' => $slot['exposure_class'], 'available' => $slot['status'] === 'active'];
        $container = null; $location = null; $nodeId = $storage['node_id'];
        if ($storage['kind'] === 'chest') {
            $chest = (new Query())->from('craft_container')->where(['id' => $storage['container_inventory_id']])->one($this->db);
            $container = ['durability' => (int)$chest['durability'], 'max_durability' => (int)$chest['max_durability']];
            $outer = (new Query())->select('s.node_id')->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')->where(['i.id' => $storage['container_inventory_id']])->one($this->db);
            $nodeId = $outer['node_id'];
        }
        if ($nodeId !== null) {
            $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node((int)$nodeId);
            $location = ['node_id' => $node['id'], 'name' => $node['name']];
        }
        return ['storage' => $this->header($storage), 'items' => $items, 'slots' => $slots, 'container' => $container, 'location' => $location, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 100), 'currentPage' => $page, 'perPage' => 100], 'server_time' => time(), 'writable' => $this->flags->capabilities()['world_write']];
    }
    private function prepare(int $user, array $payload, CraftStorage $store): array
    {
        $p = (new CanonicalInventory($store))->inspectTransfer($user, $payload['inventory_id'], $payload['destination_storage_id'], $payload['position'], $payload['quantity'], $payload['instance_id']);
        if ((int)$p['source']['id'] !== $payload['source_storage_id']) throw new GameError('SOURCE_CHANGED', 'Предмет уже перемещён. Обновите хранилище.');
        return $p;
    }
    public function preview(int $user, array $payload): array
    {
        $this->available();
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.storage.transfer', $payload, function () use ($user, $payload) {
            $p = $this->prepare($user, $payload, new CraftStorage($this->db));
            $revisions = ['inventory:' . $p['row']['id'] => (int)$p['row']['revision'], 'storage:' . $p['source']['id'] => (int)$p['source']['revision'], 'storage:' . $p['target']['id'] => (int)$p['target']['revision'], 'catalog' => (int)(new Query())->select('revision')->from('craft_meta')->where(['id' => 1])->scalar($this->db)];
            foreach ([$p['source'], $p['target']] as $storage) if ($storage['node_id'] !== null) $revisions['node:' . $storage['node_id']] = (int)(new Query())->select('revision')->from('world_node')->where(['id' => $storage['node_id']])->scalar($this->db);
            return ['revisions' => $revisions, 'terms' => ['quantity' => $p['quantity'], 'item_name' => trim($p['item']['name']), 'instance_ids' => $p['unit_ids'], 'destination_storage_id' => (int)$p['target']['id'], 'position' => $p['position']]];
        });
    }
    public function transfer(int $user, string $key, array $payload, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.storage.transfer', $payload, $quote, $revisions, function (array $input, array $terms, string $operation) use ($user, $bus) {
            $this->available(); $store = new CraftStorage($this->db); $store->operationId = $operation;
            $prepared = $this->prepare($user, $input, $store);
            if ($prepared['unit_ids'] !== $terms['instance_ids']) throw new GameError('INSTANCE_CHANGED', 'Выбранные экземпляры изменились.');
            $result = (new CanonicalInventory($store))->transfer($user, $input['inventory_id'], $input['destination_storage_id'], $input['position'], $input['quantity'], $input['instance_id']);
            $bus->emit($operation, $user, 'inventory.transferred', $result);
            $result['changed_node_ids'] = array_values(array_unique(array_map('intval', array_filter([$prepared['source']['node_id'], $prepared['target']['node_id']]))));
            return $result;
        });
    }
}
