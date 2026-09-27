<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\services\game\Locks;
use common\services\user\CreditLedger;
use yii\db\Connection;
use yii\db\Expression;

/** Internal contribution writer shared by standalone investment and explicit purchase top-up. */
class BudgetFunding
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function contribute(int $user, int $node, Money $amount, string $purpose, string $operation): array
    {
        if (!$this->db->getTransaction() || $amount->isNegative() || $amount->isZero()) throw new \LogicException('Positive authorised contribution in a command transaction required.');
        WalletSchema::requireReady($this->db);
        $accounts = (new EconomyHierarchy($this->db))->provision($node, $operation);
        $account = (new Locks($this->db))->row('economy_account', ['id' => $accounts['budget']['id']]);
        $next = Money::parse((string)$account['amount'])->add($amount);
        $walletAfter = (new CreditLedger($this->db))->changeExact($user, Money::parse('0')->subtract($amount)->decimal(), 'world_invest', 'Вклад в объект #' . $node);
        if ($this->db->createCommand()->update('economy_account', ['amount' => $next->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $account['id'], 'revision' => $account['revision']])->execute() !== 1) throw new \RuntimeException('Budget update failed.');
        $this->db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => 'investment', 'kind' => 'personal_investment', 'source_account_id' => null, 'source_user_id' => $user,
            'destination_account_id' => $account['id'], 'amount' => $amount->decimal(), 'source_after' => $walletAfter, 'destination_after' => $next->decimal(), 'purpose' => $purpose, 'created_at' => time()])->execute();
        $transfer = (int)$this->db->getLastInsertID();
        $this->db->createCommand()->insert('economy_funding_lot', ['transfer_id' => $transfer, 'budget_account_id' => $account['id'], 'investor_user_id' => $user, 'original_amount' => $amount->decimal(), 'remaining_amount' => $amount->decimal(), 'purpose' => $purpose, 'created_at' => time()])->execute();
        return ['transfer_id' => $transfer, 'wallet_after' => $walletAfter];
    }
}
