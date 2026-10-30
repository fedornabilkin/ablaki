<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\models\domain\StarterFunding;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\Locks;
use common\services\user\CreditLedger;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Shared by standalone investment and an explicitly authorised purchase top-up. */
class BudgetFunding
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function contribute(int $user, int $node, Money $amount, string $purpose, string $operation): array
    {
        if (!$this->db->getTransaction() || $amount->isNegative() || $amount->isZero()) {
            throw new \LogicException('Positive authorised contribution in a command transaction required.');
        }
        WalletSchema::requireReady($this->db);
        $accounts = (new EconomyHierarchy($this->db))->provision($node, $operation);
        $account = (new Locks($this->db))->row('economy_account', ['id' => $accounts['budget']['id']]);
        if (!$account) {
            throw new \RuntimeException('Provisioned budget missing.');
        }
        $next = Money::parse((string)$account['amount'])->add($amount);
        $walletAfter = (new CreditLedger($this->db))->changeExact(
            $user, Money::parse('0')->subtract($amount)->decimal(), 'world_invest', 'Вклад в объект #' . $node
        );
        $written = $this->db->createCommand()->update('economy_account', [
            'amount' => $next->decimal(),
            'revision' => new Expression('[[revision]]+1'),
        ], ['id' => $account['id'], 'revision' => $account['revision']])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Budget update failed.');
        }
        $line = (int)(new Query())
            ->from('economy_transfer')
            ->where(['operation_id' => $operation, 'kind' => 'personal_investment'])
            ->count('*', $this->db);
        $written = $this->db->createCommand()->insert('economy_transfer', [
            'operation_id' => $operation,
            'line_code' => $line ? 'investment-' . $line : 'investment',
            'kind' => 'personal_investment',
            'source_account_id' => null,
            'source_user_id' => $user,
            'destination_account_id' => $account['id'],
            'amount' => $amount->decimal(),
            'source_after' => $walletAfter,
            'destination_after' => $next->decimal(),
            'purpose' => $purpose,
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Investment transfer write failed.');
        }
        $transfer = (int)$this->db->getLastInsertID();
        $written = $this->db->createCommand()->insert('economy_funding_lot', [
            'transfer_id' => $transfer,
            'budget_account_id' => $account['id'],
            'investor_user_id' => $user,
            'original_amount' => $amount->decimal(),
            'remaining_amount' => $amount->decimal(),
            'purpose' => $purpose,
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Investment provenance write failed.');
        }
        $started = (new StarterFunding($this->db))->settle($node, $operation);
        return [
            'transfer_id' => $transfer,
            'wallet_after' => $walletAfter,
            'changed_node_ids' => array_merge([$node], $started),
            'funded_starter_node_ids' => $started,
        ];
    }
}