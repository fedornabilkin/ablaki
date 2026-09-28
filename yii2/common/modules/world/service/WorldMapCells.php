<?php
namespace common\modules\world\service;

use common\modules\economy\service\BudgetFunding;
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
        foreach (['x', 'y'] as $axis) if (!is_int($body[$axis] ?? null) || abs($body[$axis]) > 1000000)
            throw new GameError('INVALID_MAP_CELL', 'Укажите целые координаты ячейки.', 422);
        if (!is_bool($body['top_up'] ?? null)) throw new GameError('INVALID_MAP_CELL', 'Укажите способ оплаты.', 422);
        return ['node_id' => $node, 'x' => $body['x'], 'y' => $body['y'], 'top_up' => $body['top_up']];
    }

    private function prepare(int $user, array $input, string $action): array
    {
        $this->flags->requireFlag('world_read');
        $node = (new WorldTree($this->db))->get($input['node_id']); $this->access->requireManage($node);
        if ($node['status'] !== 'active') throw new GameError('NODE_INACTIVE', 'Объект недоступен.');
        if (in_array($node['node_type'], ['ROOM', 'BED'], true)
            || ($node['node_type'] === 'PLOT' && (new Query())->from('world_plot')->where(['node_id' => $node['id'], 'plot_kind' => 'garden'])->exists($this->db)))
            throw new GameError('MAP_NOT_EXPANDABLE', 'Эти ячейки открываются через развитие объекта.', 422);
        $where = ['parent_id' => $node['id'], 'x' => $input['x'], 'y' => $input['y']];
        $cell = (new Query())->from('world_map_cell')->where($where)->one($this->db);
        (new WorldTree($this->db))->assertFreePosition((int)$node['id'], $input['x'], $input['y']);
        if ($action === 'explore') {
            if ($cell) throw new GameError('MAP_CELL_EXPLORED', 'Ячейка уже исследована.', 422);
            $neighbours = ['or'];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as $offset)
                $neighbours[] = ['and', ['x' => $input['x'] + $offset[0]], ['y' => $input['y'] + $offset[1]]];
            $any = (new Query())->from('world_map_cell')->where(['parent_id' => $node['id']])->exists($this->db);
            if ($any && !(new Query())->from('world_map_cell')->where(['parent_id' => $node['id']])->andWhere($neighbours)->exists($this->db))
                throw new GameError('MAP_CELL_TOO_FAR', 'Исследуйте соседнюю ячейку.', 422);
            if (!$any && ($input['x'] !== 0 || $input['y'] !== 0)) throw new GameError('MAP_CELL_TOO_FAR', 'Начните исследование с координат 0, 0.', 422);
            return ['revisions' => ['node:' . $node['id'] => (int)$node['revision']],
                'terms' => ['node_id' => (int)$node['id'], 'x' => $input['x'], 'y' => $input['y'], 'action' => $action, 'price' => '0.0000', 'currency' => 'Cr']];
        }
        if ($action !== 'buy' || !$cell || $cell['state'] !== 'discovered') throw new GameError('MAP_CELL_NOT_DISCOVERED', 'Сначала исследуйте закрытую ячейку.', 422);
        WalletSchema::requireReady($this->db);
        $paid = (int)(new Query())->from('world_map_cell')->where(['parent_id' => $node['id'], 'state' => 'open'])->andWhere(['>', 'price', 0])->count('*', $this->db);
        $price = Money::parse('1.0000')->multiply($paid + 1);
        $accounts = (new EconomyHierarchy($this->db))->accounts((int)$node['id']); $budget = $accounts['budget'] ?? null;
        $available = $budget ? (new BudgetSpending($this->db))->available((int)$budget['id']) : Money::parse('0');
        $gap = $price->compare($available) > 0 ? $price->subtract($available) : Money::parse('0');
        if (!$gap->isZero() && !$input['top_up']) throw new GameError('INSUFFICIENT_BUDGET', 'Пополните бюджет объекта или выберите оплату недостающей суммы с личного баланса.');
        if (!$gap->isZero()) {
            $wallet = (new Query())->from('persone')->where(['user_id' => $user])->one($this->db);
            if (!$wallet || Money::parse((string)$wallet['credit'])->compare($gap) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'На личном балансе недостаточно кредитов.');
        }
        $recipient = $node['parent_id'] === null ? (int)$node['id'] : (int)$node['parent_id'];
        (new TreasuryLedger($this->db))->published($recipient);
        $recipientAccounts = (new EconomyHierarchy($this->db))->accounts($recipient);
        if (!isset($recipientAccounts['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна получателя недоступна.');
        $revisions = ['node:' . $node['id'] => (int)$node['revision']];
        if ($budget) $revisions['account:' . $budget['id']] = (int)$budget['revision'];
        return ['revisions' => $revisions, 'terms' => [
            'node_id' => (int)$node['id'], 'x' => $input['x'], 'y' => $input['y'], 'action' => $action,
            'price' => $price->decimal(), 'currency' => 'Cr', 'personal_charge' => $gap->decimal(),
            'available_before' => $available->decimal(), 'recipient_node_id' => $recipient,
        ]];
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
                    if (!$gap->isZero()) (new BudgetFunding($this->db))->contribute($user, $payload['node_id'], $gap, 'Покупка ячейки карты', $operation);
                    $accounts = (new EconomyHierarchy($this->db))->accounts($payload['node_id']);
                    $spending = new BudgetSpending($this->db); $amount = Money::parse($terms['price']);
                    $hold = $spending->reserve((int)$accounts['budget']['id'], $amount, 'Покупка ячейки карты', $operation);
                    $transfer = $spending->pay($hold, $terms['recipient_node_id'], $amount, $operation, 'map_cell_purchase');
                    $updated = $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'price' => $terms['price'], 'operation_id' => $operation, 'updated_at' => time()],
                        ['parent_id' => $payload['node_id'], 'x' => $payload['x'], 'y' => $payload['y'], 'state' => 'discovered'])->execute();
                    if ($updated !== 1) throw new GameError('MAP_CELL_CHANGED', 'Ячейка изменилась. Повторите расчёт.');
                } else {
                    $this->db->createCommand()->insert('world_map_cell', [
                        'parent_id' => $payload['node_id'], 'x' => $payload['x'], 'y' => $payload['y'], 'state' => 'discovered',
                        'price' => '0.0000', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time(),
                    ])->execute();
                }
                $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $payload['node_id']])->execute();
                $result = ['changed_node_ids' => [$payload['node_id']], 'cell' => ['x' => $payload['x'], 'y' => $payload['y'], 'state' => $action === 'buy' ? 'open' : 'discovered'], 'amount' => $terms['price'], 'currency' => 'Cr'];
                $bus->emit($operation, $user, 'world.map.' . $action, $result + ['transfer_id' => $transfer]);
                return $result;
            });
    }
}
