<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldQuery;
use common\modules\world\service\WorldFlags;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Owner-only accounts/journal with explicit personal contributions. GET never collects income. */
class NodeEconomy
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function node(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read');
        if (!$this->db->schema->getTableSchema('economy_account')) throw new GameError('ECONOMY_NOT_READY', 'Экономика мира ещё не подготовлена.', 503);
        // World management is deliberately not an override for another owner's finances.
        return (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
    }
    private function accounts(int $node): array
    {
        return (new EconomyHierarchy($this->db))->accounts($node);
    }
    /** No GET side effect: absent accounts are represented as zero until a domain command creates them. */
    public function view(int $user, int $node, int $page = 1): array
    {
        $place = $this->node($user, $node); $owner = $place['permissions']['storage'] && $place['has_finances'];
        if ($page < 1 || $page > 1000000) throw new GameError('INVALID_PAGINATION', 'Некорректная страница.', 422);
        $accounts = $owner ? $this->accounts($node) : []; $balances = null; $entries = []; $total = 0;
        if ($owner) {
            $budget = Money::parse((string)($accounts['budget']['amount'] ?? '0')); $reserved = Money::parse((string)($accounts['budget']['reserved'] ?? '0'));
            $balances = ['budget' => $budget->decimal(), 'reserved' => $reserved->decimal(), 'available' => $budget->subtract($reserved)->decimal(), 'treasury' => Money::parse((string)($accounts['treasury']['amount'] ?? '0'))->decimal()];
            $ids = array_column(array_intersect_key($accounts, array_flip(['budget', 'treasury'])), 'id');
            if ($ids) {
                $query = (new Query())->from('economy_transfer')->where(['or', ['source_account_id' => $ids], ['destination_account_id' => $ids]]);
                $total = (int)(clone $query)->count('*', $this->db);
                foreach ($query->orderBy(['id' => SORT_DESC])->offset(($page - 1) * 50)->limit(50)->all($this->db) as $row) $entries[] = ['id' => (int)$row['id'], 'kind' => $row['kind'], 'amount' => Money::parse((string)$row['amount'])->decimal(), 'purpose' => $row['purpose'], 'created_at' => (int)$row['created_at']];
            }
        }
        $rule = $owner ? (new EconomyHierarchy($this->db))->current($node) : null;
        $writable = $place['has_finances'] && $place['status'] === 'active' && !(new \common\modules\world\service\WorldTree($this->db))->isShelter($node) && WalletSchema::ready($this->db) && $this->flags->capabilities()['world_write'];
        $catchingUp = $owner && isset($accounts['treasury']) && (new TreasuryLedger($this->db))->catchingUp((int)$accounts['treasury']['id'], time());
        return ['node_id' => $node, 'finance_node_id' => (new EconomyHierarchy($this->db))->financialNode($node), 'has_finances' => $place['has_finances'], 'currency' => 'Cr', 'balances' => $balances, 'can_view_finances' => $owner, 'collection_rule' => $rule,
            'can_invest' => $writable,
            'can_grant' => $writable && $owner && $balances !== null && !Money::parse($balances['available'])->isZero(),
            'grant_preview' => '/v1/world/nodes/' . $node . '/budget-grant-preview', 'grant_execute' => '/v1/world/nodes/' . $node . '/budget-grant',
            'wallet_ready' => WalletSchema::ready($this->db), 'treasury_catching_up' => $catchingUp, 'collect_available' => $writable && !$catchingUp && $owner && $rule && $rule['status'] === 'published' && $rule['loss_policy'] !== null && $balances['treasury'] !== '0.0000',
            'can_pay' => $writable && $owner, 'entries' => ['items' => $entries, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 50), 'currentPage' => $page, 'perPage' => 50]], 'server_time' => time()];
    }
    public function obligations(int $user, int $node, int $page): array
    {
        $place = $this->node($user, $node);
        if (!$place['permissions']['storage']) throw new GameError('FINANCE_OWNER_REQUIRED', 'Обязательства доступны владельцу объекта.', 403);
        if ($page < 1 || $page > 1000000) throw new GameError('INVALID_PAGINATION', 'Некорректная страница.', 422);
        $accounts = $this->accounts($node); $items = []; $total = 0;
        if (isset($accounts['budget'])) {
            $query = (new Query())->select(['o.*', 'parent_node_id' => 's.node_id', 'rule_revision' => 'h.revision'])
                ->from(['o' => 'economy_obligation'])->innerJoin(['a' => 'economy_account'], '[[a.id]]=[[o.recipient_account_id]]')
                ->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[a.subject_id]]')->innerJoin(['h' => 'economy_parent_history'], '[[h.id]]=[[o.history_id]]')
                ->where(['o.budget_account_id' => $accounts['budget']['id']]);
            $total = (int)(clone $query)->count('*', $this->db);
            foreach ($query->orderBy(['o.id' => SORT_DESC])->offset(($page - 1) * 50)->limit(50)->all($this->db) as $row) $items[] = [
                'id' => (int)$row['id'], 'amount' => Money::parse((string)$row['amount'])->decimal(), 'parent_node_id' => (int)$row['parent_node_id'],
                'rule_revision' => (int)$row['rule_revision'], 'status' => $row['status'], 'created_at' => (int)$row['created_at'], 'due_at' => (int)$row['due_at'],
                'paid_at' => $row['paid_at'] === null ? null : (int)$row['paid_at'],
            ];
        }
        return ['node_id' => $node, 'items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 50), 'currentPage' => $page, 'perPage' => 50], 'server_time' => time()];
    }
    private function prepare(int $user, array $input): array
    {
        $place = $this->node($user, $input['node_id']); WalletSchema::requireReady($this->db);
        if ((new \common\modules\world\service\WorldTree($this->db))->isShelter($place['id'])) throw new GameError('SHELTER_HAS_NO_BUDGET', 'Вкладывайте кредиты в бюджет стоянки. Шалаш — переносной предмет.');
        if (!$place['has_finances']) throw new GameError('PARENT_BUDGET_REQUIRED', 'Этот объект использует бюджет родителя. Откройте родительский объект.');
        if ($place['status'] !== 'active') throw new GameError('INVESTMENT_UNAVAILABLE', 'Объект недоступен для вложений.');
        $amount = Money::parse($input['amount']);
        if ($amount->isZero() || $amount->isNegative()) throw new GameError('INVALID_AMOUNT', 'Укажите положительную сумму.', 422);
        $credit = (new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($this->db);
        if ($credit === false) throw new GameError('WALLET_UNAVAILABLE', 'Личный счёт недоступен.');
        $wallet = Money::parse((string)$credit);
        if ($wallet->compare($amount) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'Недостаточно кредитов.');
        $accounts = $this->accounts($place['id']);
        // Validate overflow before debiting the personal account. Do not expose private target balances.
        try { Money::parse((string)($accounts['budget']['amount'] ?? '0'))->add($amount); }
        catch (\OverflowException $e) { throw new GameError('BUDGET_LIMIT', 'Взнос превысит допустимый размер бюджета.'); }
        $revisions = ['node:' . $place['id'] => $place['revision']];
        if (isset($accounts['budget'])) $revisions['account:' . $accounts['budget']['id']] = (int)$accounts['budget']['revision'];
        $terms = ['node_id' => $place['id'], 'node_name' => $place['name'], 'amount' => $amount->decimal(), 'currency' => 'Cr', 'purpose' => $input['purpose'],
            'wallet_before' => $wallet->decimal(), 'wallet_after' => $wallet->subtract($amount)->decimal(), 'destination' => 'budget', 'automatic_return' => false];
        return compact('terms', 'revisions');
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.economy.invest', $input, function () use ($user, $input) { return $this->prepare($user, $input); });
    }
    public function invest(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.economy.invest', $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $bus) {
            $p = $this->prepare($user, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('INVESTMENT_CHANGED', 'Сумма или условия изменились. Повторите расчёт.');
            $amount = Money::parse($payload['amount']);
            $funding = (new BudgetFunding($this->db))->contribute($user, $payload['node_id'], $amount, $payload['purpose'], $operation);
            $transfer = $funding['transfer_id']; $walletAfter = $funding['wallet_after'];
            $bus->emit($operation, $user, 'economy.invested', ['node_id' => $payload['node_id'], 'transfer_id' => $transfer, 'amount' => $amount->decimal()]);
            return ['changed_node_ids' => [$payload['node_id']], 'amount' => $amount->decimal(), 'wallet_after' => $walletAfter, 'currency' => 'Cr'];
        });
    }
}
