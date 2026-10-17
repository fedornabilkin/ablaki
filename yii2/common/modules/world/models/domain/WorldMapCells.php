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
        $revision = EconomyRuntime::current($db);
        if ($revision) {
            $map = $revision['profile']['map'];
            return ['paid_cells' => (int)(new Query())->from('world_map_cell')->where(['parent_id' => $node, 'state' => 'open'])->andWhere(['>', 'price', 0])->count('*', $db),
                'base_price' => $map['base_price_cr'], 'next_price' => $map['base_price_cr'], 'curve' => 'rings', 'bulk_minimum' => $map['bulk_minimum'], 'discount_bps' => $map['discount_bps'], 'max_quantity' => $map['max_cells'], 'currency' => 'Cr'];
        }
        $paid = (int)(new Query())->from('world_map_cell')->where(['parent_id' => $node, 'state' => 'open'])->andWhere(['>', 'price', 0])->count('*', $db);
        $level = $db->schema->getTableSchema('world_node')->getColumn('hierarchy_level')
            ? (int)(new Query())->select('hierarchy_level')->from('world_node')->where(['id' => $node])->scalar($db)
            : 1 + (int)(new Query())->select(new Expression('MAX([[distance]])'))->from('world_node_closure')->where(['descendant_id' => $node])->scalar($db);
        $base = max(1, 8 - max(1, $level));
        return ['paid_cells' => $paid, 'base_price' => $base . '.0000', 'next_price' => $base . '.0000', 'curve' => 'rings', 'bulk_minimum' => 3, 'discount_bps' => 500, 'max_quantity' => 100, 'currency' => 'Cr'];
    }
    public static function purchasePricing(array $pricing, array $cells): array
    {
        $base = Money::parse($pricing['base_price']); $gross = Money::parse('0'); $discount = Money::parse('0'); $carry = 0; $units = [];
        $bps = count($cells) >= $pricing['bulk_minimum'] ? (int)$pricing['discount_bps'] : 0;
        foreach ($cells as $point) {
            $unit = $base->multiply(max(1, abs($point['x']), abs($point['y'])));
            $part = $unit->portion($bps, $carry); $carry = $part['carry'];
            $gross = $gross->add($unit); $discount = $discount->add($part['amount']);
            $units[] = $point + ['gross_price' => $unit->decimal(), 'price' => $unit->subtract($part['amount'])->decimal()];
        }
        return ['quantity' => count($cells), 'unit_prices' => $units, 'gross_total' => $gross->decimal(), 'discount_bps' => $bps,
            'discount_amount' => $discount->decimal(), 'total' => $gross->subtract($discount)->decimal(), 'currency' => 'Cr', 'curve' => 'rings'];
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
            $required = 1; $elixirs = 0;
            foreach ($input['cells'] as $point) {
                $level = MapExploration::requiredLevel($point['x'], $point['y']); $required = max($required, $level);
                if ($exploration['level'] < $level) $elixirs++;
            }
            if (count($input['cells']) === 1 && $input['use_elixir']) $elixirs = 1;
            if ($input['use_elixir']) {
                if ($exploration['elixir_quantity'] < $elixirs) throw new GameError('EXPLORER_ELIXIR_REQUIRED', 'В доступных ячейках рюкзака недостаточно эликсиров исследователя.');
            } elseif ($exploration['level'] < $required) throw new GameError('EXPLORER_LEVEL_REQUIRED', 'Нужен уровень исследователя ' . $required . ' или один эликсир исследователя.');
            if (count($input['cells']) > 1) $terms['elixir_quantity'] = $input['use_elixir'] ? $elixirs : 0;
            return ['revisions' => $revisions, 'terms' => $terms + ['price' => '0.0000', 'required_level' => $required, 'use_elixir' => $input['use_elixir']]];
        }
        if ($action !== 'buy' || $input['use_elixir']) throw new GameError('INVALID_MAP_ACTION', 'Некорректное действие карты.', 422);
        WalletSchema::requireReady($this->db);
        $mapPricing = self::pricing($this->db, (int)$node['id']); $paid = $mapPricing['paid_cells'];
        $pricing = self::purchasePricing($mapPricing, $input['cells']);
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
        $profile = EconomyRuntime::current($this->db);
        if ($profile) {
            $recipient = (int)(new Query())->select('n.id')->from(['c' => 'world_node_closure'])->innerJoin(['n' => 'world_node'], 'n.id=c.ancestor_id')
                ->where(['c.descendant_id' => $payer, 'n.hierarchy_level' => 3])->scalar($this->db);
            if (!$recipient) throw new GameError('SETTLEMENT_BUDGET_REQUIRED', 'Бюджет поселения ещё не подготовлен.', 503);
            $terms['economy_profile_id'] = $profile['id']; $terms['fee_receiver'] = 'settlement_budget';
        } else {
            (new TreasuryLedger($this->db))->published($recipient);
            if (!isset($hierarchy->accounts($recipient)['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна получателя недоступна.');
        }
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
                    if (isset($terms['economy_profile_id'])) EconomyPayments::charge($this->db, $user, $terms['payer_node_id'], Money::parse($terms['price']), $payload['top_up'], $operation, 'Покупка ячейки карты');
                    else {
                    $gap = Money::parse($terms['personal_charge']);
                    if (!$gap->isZero()) (new BudgetFunding($this->db))->contribute($user, $terms['payer_node_id'], $gap, 'Покупка ячейки карты', $operation);
                    $accounts = (new EconomyHierarchy($this->db))->accounts($terms['payer_node_id']);
                    $spending = new BudgetSpending($this->db); $amount = Money::parse($terms['price']);
                    $hold = $spending->reserve((int)$accounts['budget']['id'], $amount, 'Покупка ячейки карты', $operation);
                    $transfer = $spending->pay($hold, $terms['recipient_node_id'], $amount, $operation, 'map_cell_purchase');
                    }
                    foreach ($payload['cells'] as $i => $point) {
                        $updated = $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'price' => $terms['pricing']['unit_prices'][$i]['price'], 'operation_id' => $operation, 'updated_at' => time()], ['parent_id' => $payload['node_id'], 'state' => 'discovered'] + $point)->execute();
                        if ($updated !== 1) throw new GameError('MAP_CELL_CHANGED', 'Ячейка изменилась. Обновите карту.');
                    }
                } else {
                    if ($terms['use_elixir'] && ($terms['elixir_quantity'] ?? 1) > 0) {
                        $item = (new Query())->from('craft_item')->where(['code' => ExplorationCatalog::ELIXIR, 'active' => 1])->one($this->db);
                        $store = new CraftStorage($this->db); $store->operationId = $operation;
                        (new CanonicalInventory($store))->change($user, $item, -($terms['elixir_quantity'] ?? 1));
                    }
                    foreach ($payload['cells'] as $point) $this->db->createCommand()->insert('world_map_cell', ['parent_id' => $payload['node_id']] + $point + [
                        'state' => 'discovered', 'price' => '0.0000', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time(),
                    ])->execute();
                    EconomyRuntime::fact($this->db, $user, 'map_cell_explored', count($payload['cells']), $operation);
                }
                $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $payload['node_id']])->execute();
                $cells = array_map(static function ($point) use ($action) { return $point + ['state' => $action === 'buy' ? 'open' : 'discovered']; }, $payload['cells']);
                $result = ['changed_node_ids' => array_values(array_unique([$payload['node_id'], $terms['payer_node_id'] ?? $payload['node_id']])), 'cells' => $cells, 'cell' => $cells[0], 'amount' => $terms['price'], 'currency' => 'Cr'];
                $bus->emit($operation, $user, 'world.map.' . $action, $result + ['transfer_id' => $transfer]);
                return $result;
            });
    }
}
