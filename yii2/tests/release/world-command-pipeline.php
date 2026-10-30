<?php
// Reuses only the disposable, allowlisted fixture; never application database settings.
require __DIR__ . '/world-economy-runtime.php';

use common\modules\world\models\Command;
use common\modules\world\modules\economy\models\domain\BudgetDisbursement;
use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\services\user\CreditLedger;
use yii\db\Query;

final class RejectedWorldWrite extends yii\db\Command
{
    public $failTable;
    private $insertTable;

    public function insert($table, $columns)
    {
        $this->insertTable = $table;
        return parent::insert($table, $columns);
    }

    public function execute()
    {
        return $this->insertTable === $this->failTable ? 0 : parent::execute();
    }
}

function pipelineSnapshot($db): array
{
    $state = [];
    foreach ([
        'persone', 'history_balance', 'game_operation', 'game_command', 'game_quote', 'game_outbox',
        'economy_account', 'economy_transfer', 'economy_funding_lot', 'economy_spending_allocation',
        'economy_spending_commitment',
    ] as $table) {
        $state[$table] = (new Query())->from($table)->orderBy('id')->all($db);
    }
    return $state;
}

function rejectWorldWrite($db, string $table, callable $action): void
{
    $previous = $db->commandClass;
    $db->commandClass = RejectedWorldWrite::class;
    Yii::$container->set(RejectedWorldWrite::class, ['class' => RejectedWorldWrite::class, 'failTable' => $table]);
    try {
        try {
            $action();
        } catch (RuntimeException $error) {
            economyCheck(true, 'rejected ' . $table . ' write aborts the command');
            return;
        }
        throw new LogicException('A rejected ' . $table . ' write succeeded.');
    } finally {
        $db->commandClass = $previous;
        Yii::$container->clear(RejectedWorldWrite::class);
    }
}

$pipelineBus = new CommandBus($db, $flags);
$pipelinePayload = ['node_id' => $siteId, 'amount' => '0.1250'];
$pipelineHandler = static function (string $operation) use ($db, $freshUser, $siteId, $pipelineBus): array {
    $wallet = (new CreditLedger($db))->changeExact($freshUser, '-0.1250', 'fixture_pipeline', 'Pipeline acceptance');
    $pipelineBus->emit($operation, $freshUser, 'fixture.pipeline', ['node_id' => $siteId]);
    return ['wallet_after' => $wallet, 'changed_node_ids' => [$siteId, $siteId]];
};
$preparePipeline = static function () use ($pipelineBus, $freshUser, $pipelinePayload): array {
    return $pipelineBus->preview($freshUser, 'fixture.pipeline', $pipelinePayload, static fn() => [
        'revisions' => [], 'terms' => ['amount' => '0.1250'],
    ]);
};
$executePipeline = static function (string $key, ?array $quote, callable $handler) use ($pipelineBus, $freshUser, $pipelinePayload): array {
    if ($quote === null) {
        return Command::run($freshUser, $key, 'fixture.pipeline', $pipelinePayload, $handler);
    }
    return $pipelineBus->execute(
        $freshUser, $key, 'fixture.pipeline', $pipelinePayload, $quote['quote_id'], [],
        static fn($payload, $terms, $operation) => $handler($operation)
    );
};

foreach (['direct', 'quoted'] as $contract) {
    $quote = $contract === 'quoted' ? $preparePipeline() : null;
    $key = bin2hex(random_bytes(16));
    $before = pipelineSnapshot($db);
    $first = $executePipeline($key, $quote, $pipelineHandler);
    $after = pipelineSnapshot($db);
    economyCheck(count($after['history_balance']) === count($before['history_balance']) + 1, $contract . ' command writes one wallet receipt');
    economyCheck($after['history_balance'][count($after['history_balance']) - 1]['type'] === 'fixture_pipeline', $contract . ' history preserves the domain operation type');
    $expectedFingerprint = hash('sha256', CanonicalJson::encode($quote === null
        ? ['fixture.pipeline', $pipelinePayload]
        : ['type' => 'fixture.pipeline', 'version' => 1, 'payload' => $pipelinePayload, 'quote_id' => $quote['quote_id'], 'expected_revisions' => []]));
    $receipt = (new Query())->from('game_command')->where(['user_id' => $freshUser, 'request_key' => $key])->one($db);
    economyCheck($receipt['fingerprint'] === $expectedFingerprint, $contract . ' fingerprint remains compatible with existing receipts');
    $never = static function () { throw new LogicException('Replay executed the handler.'); };
    economyCheck($executePipeline($key, $quote, $never) === $first && pipelineSnapshot($db) === $after, $contract . ' retry preserves result, balance, history and events');

    $module = $app->getModule('world');
    $previousFlags = $module->flags;
    $module->flags['world_write'] = false;
    try {
        economyCheck($executePipeline($key, $quote, $never) === $first, $contract . ' accepted receipt replays while writes are disabled');
        economyReject(static fn() => $executePipeline(bin2hex(random_bytes(16)), $quote, $never), 'FEATURE_DISABLED');
    } finally {
        $module->flags = $previousFlags;
    }
    economyCheck(pipelineSnapshot($db) === $after, $contract . ' denied operation leaves no journal entry');

    if ($quote === null) {
        economyReject(static fn() => Command::run($freshUser, $key, 'fixture.changed', $pipelinePayload, $never), 'REQUEST_KEY_REUSED');
    } else {
        economyReject(static fn() => $pipelineBus->execute($freshUser, $key, 'fixture.changed', $pipelinePayload, $quote['quote_id'], [], $never), 'REQUEST_KEY_REUSED');
    }

    foreach (['game_operation', 'history_balance', 'game_outbox', 'game_command'] as $failedTable) {
        $failedQuote = $contract === 'quoted' ? $preparePipeline() : null;
        $retryKey = bin2hex(random_bytes(16));
        $beforeFailure = pipelineSnapshot($db);
        rejectWorldWrite($db, $failedTable, static fn() => $executePipeline($retryKey, $failedQuote, $pipelineHandler));
        economyCheck(pipelineSnapshot($db) === $beforeFailure, $contract . ' rollback is complete after ' . $failedTable . ' failure');
        $recovered = $executePipeline($retryKey, $failedQuote, $pipelineHandler);
        economyCheck(isset($recovered['operation_id']), $contract . ' failed key and quote remain usable after ' . $failedTable . ' failure');
    }
}

// A real contribution and wage exercise the shared FIFO journal with exact credits.
$funded = Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.fund', ['node_id' => $forestId], static function ($operation) use ($db, $freshUser, $forestId) {
    return (new BudgetFunding($db))->contribute($freshUser, $forestId, Money::parse('2.0000'), 'Pipeline funding', $operation);
});
$budgetId = (int)(new EconomyHierarchy($db))->accounts($forestId)['budget']['id'];
$wage = static function ($operation) use ($db, $freshUser, $budgetId): array {
    return ['transfer_id' => (new BudgetDisbursement($db))->wage($budgetId, $freshUser, Money::parse('0.2500'), $operation, 'fixture-wage', 'Pipeline wage')];
};
$beforeFailure = pipelineSnapshot($db);
$wageKey = bin2hex(random_bytes(16));
rejectWorldWrite($db, 'economy_spending_allocation', static fn() => Command::run($freshUser, $wageKey, 'fixture.wage', [], $wage));
economyCheck(pipelineSnapshot($db) === $beforeFailure, 'failed FIFO receipt rolls back wallet, budget, funding lots and all history');
$paid = Command::run($freshUser, $wageKey, 'fixture.wage', [], $wage);
$allocations = (new Query())->from('economy_spending_allocation')->where(['transfer_id' => $paid['transfer_id']])->all($db);
economyCheck(count($allocations) > 0, 'successful wage records the consumed contribution');
$afterWage = pipelineSnapshot($db);
economyCheck(Command::run($freshUser, $wageKey, 'fixture.wage', [], $wage) === $paid && pipelineSnapshot($db) === $afterWage, 'wage replay never duplicates payment or funding provenance');

// An allocation prepared from an older lot balance cannot silently overwrite it.
$spending = new BudgetSpending($db);
$stale = $spending->allocation($budgetId, Money::parse('0.1000'));
$lot = $stale[0];
$db->createCommand()->update('economy_funding_lot', [
    'remaining_amount' => Money::parse($lot['remaining_after'])->decimal(),
], ['id' => $lot['id']])->execute();
$beforeStale = pipelineSnapshot($db);
try {
    $db->transaction(static fn() => $spending->recordAllocation($paid['transfer_id'], $stale));
    throw new LogicException('Stale funding allocation accepted.');
} catch (RuntimeException $expected) {
    economyCheck(pipelineSnapshot($db) === $beforeStale, 'stale funding allocation is rejected without overwriting a newer balance');
}
echo 'World command pipeline checks passed.' . PHP_EOL;
