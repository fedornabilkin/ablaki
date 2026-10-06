<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\models\domain\ExpansionPolicy;
use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\models\domain\TreasuryLedger;
use common\modules\world\modules\economy\models\domain\WalletSchema;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** A closed cell is explored first, then permanently purchased from its node budget. */
class WorldMapCells
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access)
    { $this->db = $db; $this->flags = $flags; $this->access = $access; }

    public function input(int $node, array $body): array
    {
        $cells = $body['cells'] ?? [['x' => $body['x'] ?? null, 'y' => $body['y'] ?? null]];
        if (!is_array($cells) || count($cells) < 1 || count($cells) > 100) throw new GameError('INVALID_MAP_CELL', 'Выберите от 1 до 100 ячеек.', 422);
        $normalized = [];
        foreach ($cells as $cell) {
            if (!is_array($cell)) throw new GameError('INVALID_MAP_CELL', 'Некорректные координаты.', 422);
            foreach (['x', 'y'] as $axis) if (!is_int($cell[$axis] ?? null) || abs($cell[$axis]) > 1000000) throw new GameError('INVALID_MAP_CELL', 'Укажите целые координаты ячейки.', 422);
            $key = $cell['x'] . ':' . $cell['y'];
            if (isset($normalized[$key])) throw new GameError('INVALID_MAP_CELL', 'Ячейки не должны повторяться.', 422);
            $normalized[$key] = ['x' => $cell['x'], 'y' => $cell['y']];
        }
        $cells = array_values($normalized);
        usort($cells, static function ($a, $b) { return [$a['y'], $a['x']] <=> [$b['y'], $b['x']]; });
        if (!is_bool($body['top_up'] ?? null) || !is_bool($body['use_elixir'] ?? false)) throw new GameError('INVALID_MAP_CELL', 'Укажите способ оплаты и исследования.', 422);
        return ['node_id' => $node, 'cells' => $cells, 'top_up' => $body['top_up'], 'use_elixir' => $body['use_elixir'] ?? false];
    }

    /** Prices are a read projection, without creating a quote or changing inventory. */
    public static function pricing(Connection $db, int $node): array
    {
        $paid = (int)(new Query())->from('world_map_cell')->where(['parent_id' => $node, 'state' => 'open'])->andWhere(['>', 'price', 0])->count('*', $db);
        return ['paid_cells' => $paid, 'base_price' => '1.0000', 'next_price' => ($paid + 1) . '.0000', 'bulk_minimum' => 3, 'discount_bps' => 500, 'max_quantity' => 100, 'currency' => 'Cr'];
    }

    private function prepare(int $user, array $input, string $action): array
    {
        $this->flags->requireFlag('world_read');
        $tree = new WorldTree($this->db); $node = $tree->get($input['node_id']); $this->access->requireManage($node);
        $exploration = (new MapExploration($this->db))->state($node, $user);
        if (!$exploration['allowed']) throw new GameError('MAP_NOT_EXPANDABLE', $exploration['reason'], 422);
        $revisions = ['node:' . $node['id'] => (int)$node['revision']];
        foreach ($input['cells'] as $point) {
            $tree->assertFreePosition((int)$node['id'], $point['x'], $point['y']);
            $cell = (new Query())->from('world_map_cell')->where(['parent_id' => $node['id']] + $point)->one($this->db);
            if ($action === 'explore' && $cell) throw new GameError('MAP_CELL_EXPLORED', 'Ячейка уже исследована.', 422);
            if ($action === 'buy' && (!$cell || $cell['state'] !== 'discovered')) throw new GameError('MAP_CELL_NOT_DISCOVERED', 'Сначала исследуйте все выбранные ячейки.', 422);
        }
        $terms = ['node_id' => (int)$node['id'], 'cells' => $input['cells'], 'action' => $action, 'currency' => 'Cr'];
        if ($action === 'explore') {
            if (count($input['cells']) !== 1) throw new GameError('EXPLORE_ONE_CELL', 'Исследуйте по одной ячейке.', 422);
            $point = $input['cells'][0]; $required = MapExploration::requiredLevel($point['x'], $point['y']);
            if ($input['use_elixir']) {
                if ($exploration['elixir_quantity'] < 1) throw new GameError('EXPLORER_ELIXIR_REQUIRED', 'В доступных ячейках рюкзака нет эликсира исследователя.');
            } elseif ($exploration['level'] < $required) throw new GameError('EXPLORER_LEVEL_REQUIRED', 'Нужен уровень исследователя ' . $required . ' или один эликсир исследователя.');
            return ['revisions' => $revisions, 'terms' => $terms + ['price' => '0.0000', 'required_level' => $required, 'use_elixir' => $input['use_elixir']]];
        }
        if ($action !== 'buy' || $input['use_elixir']) throw new GameError('INVALID_MAP_ACTION', 'Некорректное действие карты.', 422);
        WalletSchema::requireReady($this->db);
        $paid = self::pricing($this->db, (int)$node['id'])['paid_cells'];
        $pricing = (new ExpansionPolicy())->quote(0, $paid, max(10000, $paid + count($input['cells'])), '1.0000', count($input['cells']));
        $price = Money::parse($pricing['total']);
        $hierarchy = new EconomyHierarchy($this->db); $payer = $hierarchy->financialNode((int)$node['id']);
        $accounts = $hierarchy->accounts($payer); $budget = $accounts['budget'] ?? null;
        $available = $budget ? (new BudgetSpending($this->db))->available((int)$budget['id']) : Money::parse('0');
        $gap = $price->compare($available) > 0 ? $price->subtract($available) : Money::parse('0');
        if (!$gap->isZero() && !$input['top_up']) throw new GameError('INSUFFICIENT_BUDGET', 'Пополните бюджет объекта или выберите оплату недостающей суммы с личного баланса.');
        if (!$gap->isZero()) {
            $wallet = (new Query())->from('persone')->where(['user_id' => $user])->one($this->db);
            if (!$wallet || Money::parse((string)$wallet['credit'])->compare($gap) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'На личном балансе недостаточно кредитов.');
        }
        $payerNode = $tree->get($payer);
        $recipient = $payerNode['parent_id'] === null ? $payer : $hierarchy->financialNode((int)$payerNode['parent_id']);
        (new TreasuryLedger($this->db))->published($recipient);
        if (!isset($hierarchy->accounts($recipient)['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна получателя недоступна.');
        $revisions['node:' . $payer] = (int)$payerNode['revision'];
        if ($budget) $revisions['account:' . $budget['id']] = (int)$budget['revision'];
        return ['revisions' => $revisions, 'terms' => $terms + ['price' => $price->decimal(), 'pricing' => $pricing, 'personal_charge' => $gap->decimal(), 'payer_node_id' => $payer,
            'available_before' => $available->decimal(), 'recipient_node_id' => $recipient]];
    }

    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.map.' . $action, $input,
            function () use ($user, $input, $action) { return $this->prepare($user, $input, $action); });
    }

    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.map.' . $action, $input, $quote, $revisions,
            function (array $payload, array $terms, string $operation) use ($user, $action, $bus) {
                $current = $this->prepare($user, $payload, $action);
                if (CanonicalJson::encode($current['terms']) !== CanonicalJson::encode($terms)) throw new GameError('MAP_PRICE_CHANGED', 'Стоимость ячейки изменилась. Повторите расчёт.');
                $transfer = null;
                if ($action === 'buy') {
                    $gap = Money::parse($terms['personal_charge']);
                    if (!$gap->isZero()) (new BudgetFunding($this->db))->contribute($user, $terms['payer_node_id'], $gap, 'Покупка ячейки карты', $operation);
                    $accounts = (new EconomyHierarchy($this->db))->accounts($terms['payer_node_id']);
                    $spending = new BudgetSpending($this->db); $amount = Money::parse($terms['price']);
                    $hold = $spending->reserve((int)$accounts['budget']['id'], $amount, 'Покупка ячейки карты', $operation);
                    $transfer = $spending->pay($hold, $terms['recipient_node_id'], $amount, $operation, 'map_cell_purchase');
                    foreach ($payload['cells'] as $i => $point) {
                        $updated = $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'price' => $terms['pricing']['unit_prices'][$i]['price'], 'operation_id' => $operation, 'updated_at' => time()], ['parent_id' => $payload['node_id'], 'state' => 'discovered'] + $point)->execute();
                        if ($updated !== 1) throw new GameError('MAP_CELL_CHANGED', 'Ячейка изменилась. Обновите карту.');
                    }
                } else {
                    if ($terms['use_elixir']) {
                        $item = (new Query())->from('craft_item')->where(['code' => ExplorationCatalog::ELIXIR, 'active' => 1])->one($this->db);
                        $store = new CraftStorage($this->db); $store->operationId = $operation;
                        (new CanonicalInventory($store))->change($user, $item, -1);
                    }
                    $this->db->createCommand()->insert('world_map_cell', ['parent_id' => $payload['node_id']] + $payload['cells'][0] + [
                        'state' => 'discovered', 'price' => '0.0000', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time(),
                    ])->execute();
                }
                $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $payload['node_id']])->execute();
                $cells = array_map(static function ($point) use ($action) { return $point + ['state' => $action === 'buy' ? 'open' : 'discovered']; }, $payload['cells']);
                $result = ['changed_node_ids' => array_values(array_unique([$payload['node_id'], $terms['payer_node_id'] ?? $payload['node_id']])), 'cells' => $cells, 'cell' => $cells[0], 'amount' => $terms['price'], 'currency' => 'Cr'];
                $bus->emit($operation, $user, 'world.map.' . $action, $result + ['transfer_id' => $transfer]);
                return $result;
            });
    }
}
