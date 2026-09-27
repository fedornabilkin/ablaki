<?php
namespace common\modules\world\service;

use common\modules\craft\service\CanonicalInventory;
use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\StorageAccessPolicy;
use common\modules\economy\service\BudgetSpending;
use common\modules\economy\service\EconomyHierarchy;
use common\modules\economy\service\TreasuryLedger;
use common\modules\economy\service\WalletSchema;
use common\modules\economy\value\Money;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Immediate contracted maintenance, paid by the building budget, raw materials from its owner's backpack. */
class WorldBuildingRepair
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function context(int $user, int $node): array
    {
        $c = (new WorldBuildingOperation($this->db, $this->flags))->context($user, $node, true);
        $purchase = (new Query())->from('world_premises_purchase')->where(['building_id' => $node, 'user_id' => $user])->one($this->db);
        $terms = $purchase ? json_decode($purchase['terms_json'], true, 512, JSON_THROW_ON_ERROR) : [];
        $effective = $purchase ? (new WorldRepairContracts($this->db, $this->flags))->effective($purchase, $terms) : null;
        $rule = $effective['repair'] ?? null; $cost = null;
        if (!$rule) $c['reasons'][] = 'Нет договора ремонта. Выберите предложение в разделе договоров этой постройки.';
        else {
            if ((int)$purchase['plot_id'] !== (int)$c['node']['parent_id'] || !in_array((int)$purchase['room_id'], $c['ids'], true)) $c['reasons'][] = 'Принадлежность купленной постройки требует сверки.';
            try {
                $spec = new BuildingRepairSpec($this->db); $rule = $spec->resolve($rule);
                $cost = $spec->cost($rule, (int)$c['building']['condition'], (int)$c['building']['max_condition']);
                if ($cost['restore'] === 0) $c['reasons'][] = 'Постройка уже целая.';
            } catch (GameError $error) { $c['reasons'][] = $error->getMessage(); }
        }
        $accounts = (new EconomyHierarchy($this->db))->accounts($node); $budget = $accounts['budget'] ?? null;
        $available = $budget ? (new BudgetSpending($this->db))->available((int)$budget['id'])->decimal() : '0.0000';
        if ($cost && Money::parse($available)->compare(Money::parse($cost['price'])) < 0) $c['reasons'][] = 'Пополните бюджет постройки: свободных средств недостаточно.';
        if ($cost) {
            $inventory = new CanonicalInventory(new CraftStorage($this->db)); $backpack = $inventory->backpack($user); $stock = [];
            if ($backpack) {
                $storage = (new StorageAccessPolicy($this->db))->storage($user, (int)$backpack['id']);
                $rows = (new Query())->select(['item_id', 'quantity' => new Expression('SUM([[item_quantity]])')])->from('craft_inventory')
                    ->where(['storage_id' => $storage['id'], 'user_id' => $user, 'item_id' => array_column($cost['materials'], 'item_id')])
                    ->andWhere(['between', 'slot', 1, $storage['capacity']])->andWhere(['>', 'item_quantity', 0])->groupBy('item_id')->all($this->db);
                foreach ($rows as $row) $stock[(int)$row['item_id']] = (int)$row['quantity'];
            }
            foreach ($cost['materials'] as &$material) {
                $material['have'] = $stock[$material['item_id']] ?? 0; $material['available'] = $material['have'] >= $material['quantity'];
                if (!$material['available']) $c['reasons'][] = 'Не хватает материала «' . $material['name'] . '» в доступных ячейках рюкзака.';
            }
            unset($material);
        }
        return $c + compact('purchase', 'terms', 'effective', 'rule', 'cost', 'budget', 'available');
    }
    public function state(int $user, int $node): array
    {
        $c = $this->context($user, $node);
        return ['node_id' => $node, 'supported' => $c['rule'] !== null, 'writable' => $this->flags->capabilities()['world_write'] && WalletSchema::ready($this->db),
            'available' => !$c['reasons'], 'reasons' => $c['reasons'], 'repair' => $c['cost'], 'available_budget' => $c['available'], 'server_time' => time()];
    }
    private function prepare(int $user, array $input): array
    {
        WalletSchema::requireReady($this->db); $c = $this->context($user, $input['node_id']);
        if ($c['reasons']) throw new GameError('BUILDING_REPAIR_UNAVAILABLE', implode(' ', $c['reasons']));
        $recipient = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node((int)$c['terms']['recipient_node_id']);
        if ($recipient['type'] !== 'SETTLEMENT' || $recipient['status'] !== 'active' || $recipient['root_id'] !== $c['node']['root_id']
            || (new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $recipient['id']])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('REPAIR_RECIPIENT_UNAVAILABLE', 'Поселение-подрядчик недоступно.');
        $policy = (new TreasuryLedger($this->db))->published($recipient['id']); $accounts = (new EconomyHierarchy($this->db))->accounts($recipient['id']);
        if (!isset($accounts['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна поселения недоступна.');
        $price = Money::parse($c['cost']['price']);
        Money::parse((string)$accounts['treasury']['amount'])->add($price);
        (new BudgetSpending($this->db))->allocation((int)$c['budget']['id'], $price);
        $materials = (new ConstructionSpec($this->db))->materials($user, $c['cost']);
        $revisions = ['node:' . $input['node_id'] => $c['node']['revision'], 'node:' . $recipient['id'] => $recipient['revision'], 'account:' . $c['budget']['id'] => (int)$c['budget']['revision']] + $materials['revisions'];
        foreach ($c['rooms'] as $room) $revisions['node:' . $room['id']] = (int)$room['revision'];
        $terms = $input + ['purchase_id' => (int)$c['purchase']['id'], 'template_revision_id' => (int)$c['terms']['template_revision_id'], 'name' => $c['node']['name'],
            'repair_contract_id' => $c['effective']['id'], 'repair_template_revision_id' => $c['effective']['template_revision_id'],
            'repair' => $c['cost'], 'full_price' => $c['rule']['full_price'], 'source_budget_id' => (int)$c['budget']['id'], 'available_before' => $c['available'], 'personal_charge' => '0.0000',
            'recipient_node_id' => $recipient['id'], 'recipient_name' => $recipient['name'], 'recipient_account_id' => (int)$accounts['treasury']['id'], 'recipient_policy_id' => $policy['history_id'],
            'status_before' => $c['building']['operational_status'], 'status_after' => $c['building']['operational_status'] === 'active' && (int)$c['building']['condition'] > 0 ? 'active' : 'paused', 'material_plan' => $materials['plan']];
        // The purchase's original terms are kept separately; the quote describes this repair only.
        return ['context' => $c, 'terms' => $terms, 'revisions' => $revisions];
    }
    private function prepared(int $user, array $input): array
    {
        try { return $this->prepare($user, $input); }
        catch (\OverflowException $e) { throw new GameError('REPAIR_AMOUNT_LIMIT', 'Сумма ремонта или остаток казны превышают допустимый предел.', 422); }
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.building.repair', $input, function () use ($user, $input) { return $this->prepared($user, $input); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.building.repair', $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $bus) {
            $p = $this->prepared($user, $payload); $c = $p['context'];
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('BUILDING_REPAIR_CHANGED', 'Условия ремонта изменились. Повторите расчёт.');
            $price = Money::parse($terms['repair']['price']); $spend = new BudgetSpending($this->db);
            $hold = $spend->reserve($terms['source_budget_id'], $price, 'Ремонт постройки: ' . $terms['name'], $operation);
            $transfer = $spend->pay($hold, $terms['recipient_node_id'], $price, $operation, 'building_repair');
            $store = new CraftStorage($this->db); $store->operationId = $operation; $inventory = new CanonicalInventory($store); $storages = [];
            foreach ($terms['material_plan'] as $material) $storages[] = $inventory->consumeBuildingRepair($user, $material['inventory_id'], $material['item_id'], $material['quantity']);
            if ($this->db->createCommand()->update('world_building', ['condition' => $terms['repair']['condition_after'], 'operational_status' => $terms['status_after']],
                ['node_id' => $payload['node_id'], 'condition' => $terms['repair']['condition_before'], 'operational_status' => $terms['status_before'], 'active_project_id' => null])->execute() !== 1) throw new \RuntimeException('Building repair update failed.');
            $ancestors = array_map('intval', (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $payload['node_id']])->column($this->db));
            $ids = array_values(array_unique(array_merge($c['ids'], $ancestors, [$terms['recipient_node_id']])));
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $ids])->execute();
            $locations = array_map('intval', (new Query())->select('id')->from('craft_storage')->where(['node_id' => $c['ids']])->column($this->db));
            if ($locations) $this->db->createCommand()->update('craft_storage', ['revision' => new Expression('[[revision]]+1')], ['id' => $locations])->execute();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            $result = ['changed_node_ids' => $ids, 'changed_storage_ids' => array_values(array_unique(array_merge($storages, $locations)))];
            (new WorldTree($this->db))->audit($user, 'world.building.repair', 'Ремонт из бюджета постройки', $terms, ['id' => $payload['node_id'], 'condition' => $terms['repair']['condition_after'], 'operational_status' => $terms['status_after'], 'transfer_id' => $transfer], $operation);
            $bus->emit($operation, $user, 'world.building.repaired', $result); return $result;
        });
    }
}
