<?php
namespace common\modules\craft\service;

use common\modules\world\service\WorldFlags;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Recovery accepts only the owner's items from an objectively unavailable world location. */
class StorageRecovery
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function available(): void { $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2'); }
    public function cause(int $node): ?array
    {
        $row = (new Query())->select(['n.id', 'n.revision', 'n.status', 'b.condition', 'b.operational_status'])->from(['p' => 'world_node_closure'])
            ->innerJoin(['n' => 'world_node'], '[[n.id]]=[[p.ancestor_id]]')->leftJoin(['b' => 'world_building'], '[[b.node_id]]=[[n.id]]')
            ->where(['p.descendant_id' => $node])->andWhere(['or', ['n.status' => ['archived', 'destroyed']], ['b.operational_status' => 'destroyed'], ['<=', 'b.condition', 0]])->orderBy(['p.distance' => SORT_ASC])->one($this->db);
        return $row ?: null;
    }
    public function prepare(int $user, int $inventory): array
    {
        $this->available();
        $row = (new Query())->from('craft_inventory')->where(['id' => $inventory, 'user_id' => $user])->andWhere(['>', 'item_quantity', 0])->one($this->db);
        $source = $row ? (new Query())->from('craft_storage')->where(['id' => $row['storage_id'], 'owner_user_id' => $user, 'kind' => ['placement', 'stockpile'], 'status' => 'active'])->one($this->db) : null;
        $cause = $source && $source['node_id'] !== null ? $this->cause((int)$source['node_id']) : null;
        if (!$cause) throw new GameError('RECOVERY_UNAVAILABLE', 'Восстановление доступно только для ваших вещей в разрушенном или архивном объекте.');
        $item = (new Query())->from('craft_item')->where(['id' => $row['item_id']])->one($this->db);
        if (!$item) throw new GameError('ITEM_UNAVAILABLE', 'Предмет требует сверки.');
        return compact('row', 'source', 'cause', 'item');
    }
    public function listing(int $user, int $page): array
    {
        $this->available();
        if ($page < 1 || $page > 1000000) throw new GameError('INVALID_PAGINATION', 'Некорректная страница.', 422);
        $damaged = (new Query())->select('p.descendant_id')->from(['p' => 'world_node_closure'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[p.ancestor_id]]')->leftJoin(['b' => 'world_building'], '[[b.node_id]]=[[n.id]]')
            ->where(['or', ['n.status' => ['archived', 'destroyed']], ['b.operational_status' => 'destroyed'], ['<=', 'b.condition', 0]]);
        $query = (new Query())->select(['i.id', 'i.item_quantity', 'd.name', 's.node_id'])->from(['i' => 'craft_inventory'])
            ->innerJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')->innerJoin(['d' => 'craft_item'], '[[d.id]]=[[i.item_id]]')
            ->where(['i.user_id' => $user, 's.owner_user_id' => $user, 's.status' => 'active', 's.kind' => ['placement', 'stockpile']])->andWhere(['>', 'i.item_quantity', 0])->andWhere(['in', 's.node_id', $damaged]);
        $total = (int)(clone $query)->count('*', $this->db); $items = [];
        foreach ($query->orderBy(['i.id' => SORT_ASC])->offset(($page - 1) * 50)->limit(50)->all($this->db) as $row) $items[] = ['inventory_id' => (int)$row['id'], 'name' => trim($row['name']), 'quantity' => (int)$row['item_quantity'], 'node_id' => (int)$row['node_id']];
        $recovery = (new Query())->select('id')->from('craft_storage')->where(['identity_key' => 'recovery:user:' . $user, 'owner_user_id' => $user, 'status' => 'active'])->scalar($this->db);
        return ['items' => $items, 'recovery_storage_id' => $recovery ? (int)$recovery : null, 'writable' => $this->flags->capabilities()['world_write'], '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 50), 'currentPage' => $page, 'perPage' => 50], 'server_time' => time()];
    }
    public function preview(int $user, int $inventory): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.storage.recover', ['inventory_id' => $inventory], function () use ($user, $inventory) {
            $p = $this->prepare($user, $inventory);
            return ['revisions' => ['inventory:' . $inventory => (int)$p['row']['revision'], 'storage:' . $p['source']['id'] => (int)$p['source']['revision'], 'node:' . $p['cause']['id'] => (int)$p['cause']['revision']],
                'terms' => ['inventory_id' => $inventory, 'name' => trim($p['item']['name']), 'quantity' => (int)$p['row']['item_quantity'], 'source_storage_id' => (int)$p['source']['id'], 'cause_node_id' => (int)$p['cause']['id'], 'contents_preserved' => true]];
        });
    }
    public function recover(int $user, string $key, int $inventory, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.storage.recover', ['inventory_id' => $inventory], $quote, $revisions, function (array $input, array $terms, string $operation) use ($user, $bus) {
            $p = $this->prepare($user, $input['inventory_id']);
            if ((int)$p['source']['id'] !== $terms['source_storage_id'] || (int)$p['cause']['id'] !== $terms['cause_node_id'] || (int)$p['row']['item_quantity'] !== $terms['quantity']) throw new GameError('RECOVERY_CHANGED', 'Условия восстановления изменились.');
            $store = new CraftStorage($this->db); $store->operationId = $operation;
            $result = (new CanonicalInventory($store))->recover($user, $p['row'], $p['source']);
            $bus->emit($operation, $user, 'inventory.recovered', ['inventory_id' => $input['inventory_id'], 'source_node_id' => (int)$p['source']['node_id'], 'cause_node_id' => (int)$p['cause']['id'], 'storage_id' => $result['recovery_storage_id']]);
            return $result + ['changed_node_ids' => [(int)$p['source']['node_id']]];
        });
    }
}
