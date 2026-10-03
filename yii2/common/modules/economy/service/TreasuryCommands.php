<?php
namespace common\modules\economy\service;

use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldQuery;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;

class TreasuryCommands
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function prepare(int $user, array $input, bool $pay): array
    {
        $this->flags->requireFlag('world_read'); WalletSchema::requireReady($this->db);
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($input['node_id']);
        if (!$node['has_finances']) throw new GameError('PARENT_BUDGET_REQUIRED', 'У этого объекта нет отдельной казны. Откройте родительский объект.', 422);
        if (!$node['permissions']['storage']) throw new GameError('FINANCE_OWNER_REQUIRED', 'Казной и бюджетом распоряжается владелец объекта.', 403);
        if ($node['status'] !== 'active') throw new GameError('FINANCE_UNAVAILABLE', 'Объект недоступен.');
        $accounts = (new EconomyHierarchy($this->db))->accounts($node['id']);
        if (!isset($accounts['budget'], $accounts['treasury'])) throw new GameError('TREASURY_EMPTY', 'У объекта пока нет поступлений.');
        $ledger = new TreasuryLedger($this->db);
        try { $plan = $pay ? $ledger->planPayment($node['id'], $accounts, $input['obligation_id']) : $ledger->planCollection($node['id'], $accounts, time()); }
        catch (\OverflowException $e) { throw new GameError('ACCOUNT_LIMIT', 'Операция превысит допустимый размер счёта.'); }
        $revisions = ['node:' . $node['id'] => $node['revision']];
        foreach (['budget', 'treasury'] as $role) $revisions['account:' . $accounts[$role]['id']] = (int)$accounts[$role]['revision'];
        return ['terms' => $plan['terms'], 'revisions' => $revisions, 'plan' => $plan, 'accounts' => $accounts];
    }
    public function preview(int $user, array $input, bool $pay): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, $pay ? 'world.economy.pay' : 'world.economy.collect', $input, function () use ($user, $input, $pay) { return $this->prepare($user, $input, $pay); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, bool $pay): array
    {
        $bus = new CommandBus($this->db, $this->flags); $type = $pay ? 'world.economy.pay' : 'world.economy.collect';
        return $bus->execute($user, $key, $type, $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $pay, $bus, $type) {
            $prepared = $this->prepare($user, $payload, $pay);
            if (CanonicalJson::encode($terms) !== CanonicalJson::encode($prepared['terms'])) throw new GameError('TREASURY_CHANGED', 'Поступления или условия изменились. Повторите расчёт.');
            $ledger = new TreasuryLedger($this->db);
            if ($pay) $ledger->pay($prepared['accounts'], $prepared['plan'], $operation);
            else $ledger->collect($payload['node_id'], $prepared['accounts'], $prepared['plan'], $operation);
            $changed = [$payload['node_id']];
            if ($pay) $changed[] = $terms['parent_node_id'];
            $bus->emit($operation, $user, $type, ['node_id' => $payload['node_id'], 'changed_node_ids' => $changed]);
            return ['changed_node_ids' => array_values(array_unique($changed))];
        });
    }
}
