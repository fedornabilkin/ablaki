<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\Money;
use common\modules\world\models\domain\WorldAccessPolicy;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\models\domain\WorldQuery;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Targeted budget-to-budget funding. The source and recipient remain separate owners of their funds. */
class BudgetGrants
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }

    private function prepare(int $user, array $input): array
    {
        $this->flags->requireFlag('world_read'); WalletSchema::requireReady($this->db);
        if (!$this->db->schema->getTableSchema('economy_budget_grant') || !$this->db->schema->getTableSchema('economy_grant_allocation'))
            throw new GameError('ECONOMY_NOT_READY', 'Схема целевого финансирования ещё не подготовлена.', 503);
        $reader = new WorldQuery($this->db, new WorldAccessPolicy($user));
        $source = $reader->node($input['source_node_id']); $target = $reader->node($input['destination_node_id']);
        if (!$source['has_finances'] || !$target['has_finances']) throw new GameError('PARENT_BUDGET_REQUIRED', 'Комнаты, грядки и склады используют бюджет родительского объекта.', 422);
        if ($source['id'] === $target['id'] || $source['root_id'] !== $target['root_id'] || $source['status'] !== 'active' || $target['status'] !== 'active'
            || !$source['permissions']['storage'] || !$target['permissions']['storage']) {
            throw new GameError('GRANT_FORBIDDEN', 'Перевод доступен между собственными действующими объектами одного мира.', 403);
        }
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')
            ->where(['c.descendant_id' => [$source['id'], $target['id']]])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('GRANT_UNAVAILABLE', 'Одна из территорий временно недоступна.');
        $hierarchy = new EconomyHierarchy($this->db); $rule = $hierarchy->current($target['id']);
        if (!$rule) throw new GameError('GRANT_TARGET_UNAVAILABLE', 'Финансовые правила получателя ещё не подготовлены.');
        $directParent = $rule['parent_node_id'] === $source['id'];
        $descendant = (new Query())->from('world_node_closure')->where(['ancestor_id' => $source['id'], 'descendant_id' => $target['id']])->exists($this->db);
        $garden = (new Query())->from(['p' => 'world_garden_purchase'])->innerJoin(['m' => 'world_membership'], '[[m.id]]=[[p.membership_id]]')
            ->where(['p.node_id' => $target['id'], 'm.starter_site_id' => $source['id'], 'm.user_id' => $user])->exists($this->db);
        if (!$directParent && !$descendant && !$garden) throw new GameError('GRANT_RELATION_REQUIRED', 'Бюджет можно направить дочернему объекту или своему огороду.', 403);
        $amount = Money::parse($input['amount']);
        if ($amount->isZero() || $amount->isNegative()) throw new GameError('INVALID_AMOUNT', 'Укажите положительную сумму.', 422);
        $sourceAccounts = $hierarchy->accounts($source['id']); $targetAccounts = $hierarchy->accounts($target['id']);
        if (!isset($sourceAccounts['budget'], $targetAccounts['budget'])) throw new GameError('BUDGET_UNAVAILABLE', 'Счета объектов ещё не подготовлены.');
        $from = $sourceAccounts['budget']; $to = $targetAccounts['budget']; $spending = new BudgetSpending($this->db);
        if ($spending->available((int)$from['id'])->compare($amount) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Недостаточно свободных средств бюджета.');
        try { $after = Money::parse((string)$to['amount'])->add($amount); }
        catch (\OverflowException $e) { throw new GameError('BUDGET_LIMIT', 'Перевод превысит допустимый размер бюджета.'); }
        $terms = ['source_node_id' => $source['id'], 'destination_node_id' => $target['id'], 'source_budget_id' => (int)$from['id'],
            'destination_budget_id' => (int)$to['id'], 'parent_history_id' => $rule['history_id'], 'amount' => $amount->decimal(),
            'source_after' => Money::parse((string)$from['amount'])->subtract($amount)->decimal(), 'destination_after' => $after->decimal(),
            'source_available_after' => $spending->available((int)$from['id'])->subtract($amount)->decimal(), 'purpose' => $input['purpose'], 'currency' => 'Cr'];
        $revisions = ['node:' . $source['id'] => $source['revision'], 'node:' . $target['id'] => $target['revision'],
            'account:' . $from['id'] => (int)$from['revision'], 'account:' . $to['id'] => (int)$to['revision']];
        return compact('terms', 'revisions');
    }

    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.economy.grant', $input,
            function () use ($user, $input) { return $this->prepare($user, $input); });
    }

    public function execute(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.economy.grant', $input, $quote, $revisions,
            function (array $payload, array $terms, string $operation) use ($user, $bus) {
                $prepared = $this->prepare($user, $payload);
                if (CanonicalJson::encode($prepared['terms']) !== CanonicalJson::encode($terms)) throw new GameError('GRANT_CHANGED', 'Условия перевода изменились. Повторите расчёт.');
                $locks = new Locks($this->db); $ids = [$terms['source_budget_id'], $terms['destination_budget_id']]; sort($ids, SORT_NUMERIC);
                foreach ($ids as $id) if (!$locks->row('economy_account', ['id' => $id])) throw new GameError('BUDGET_UNAVAILABLE', 'Счёт недоступен.');
                $amount = Money::parse($terms['amount']); $spending = new BudgetSpending($this->db);
                if ($spending->available($terms['source_budget_id'])->compare($amount) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Недостаточно свободных средств бюджета.');
                $allocations = $spending->allocation($terms['source_budget_id'], $amount);
                foreach (['source' => $terms['source_budget_id'], 'destination' => $terms['destination_budget_id']] as $role => $id) {
                    $after = $terms[$role . '_after'];
                    if ($this->db->createCommand()->update('economy_account', ['amount' => $after, 'revision' => new Expression('[[revision]]+1')],
                        ['id' => $id, 'revision' => $prepared['revisions']['account:' . $id]])->execute() !== 1) throw new \RuntimeException('Budget grant changed concurrently.');
                }
                $now = time();
                $this->db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => 'grant', 'kind' => 'budget_grant',
                    'source_account_id' => $terms['source_budget_id'], 'source_user_id' => null, 'destination_account_id' => $terms['destination_budget_id'],
                    'amount' => $terms['amount'], 'source_after' => $terms['source_after'], 'destination_after' => $terms['destination_after'],
                    'purpose' => $terms['purpose'], 'created_at' => $now])->execute();
                $transfer = (int)$this->db->getLastInsertID();
                $this->db->createCommand()->insert('economy_budget_grant', ['source_account_id' => $terms['source_budget_id'], 'destination_account_id' => $terms['destination_budget_id'],
                    'parent_history_id' => $terms['parent_history_id'], 'operation_id' => $operation, 'transfer_id' => $transfer,
                    'purpose' => $terms['purpose'], 'amount' => $terms['amount'], 'created_at' => $now])->execute();
                $grant = (int)$this->db->getLastInsertID();
                foreach ($allocations as $allocation) {
                    if ($this->db->createCommand()->update('economy_funding_lot', ['remaining_amount' => $allocation['remaining_after']], ['id' => $allocation['id']])->execute() !== 1) throw new \RuntimeException('Funding lot changed concurrently.');
                    $this->db->createCommand()->insert('economy_grant_allocation', ['grant_id' => $grant, 'funding_lot_id' => $allocation['id'], 'amount' => $allocation['amount']])->execute();
                }
                $result = ['changed_node_ids' => [$terms['source_node_id'], $terms['destination_node_id']], 'amount' => $terms['amount'],
                    'source_available_after' => $terms['source_available_after'], 'destination_budget_after' => $terms['destination_after'],
                    'transfer_id' => $transfer, 'grant_id' => $grant, 'currency' => 'Cr'];
                $bus->emit($operation, $user, 'world.economy.granted', $result);
                return $result;
            });
    }
}
