<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\TreasuryLedger;
use common\modules\world\modules\economy\value\Money;
use common\services\user\CreditLedger;
use yii\db\Connection;

/** Transfers existing credits; delivery and buyback fees have no receiving wallet. */
final class MarketSettlement
{
    public function __construct(private readonly Connection $db) {}

    public function pay(int $payer, ?int $recipient, Money $amount, string $operation, string $line, string $label = 'Рынок', string $type = 'market_trade'): void
    {
        if ($amount->isZero()) return;
        if (!$this->db->getTransaction() || $amount->isNegative()) throw new \LogicException('Market payment needs a transaction and a positive amount.');
        $ledger = new CreditLedger($this->db); $kind = $recipient === null ? 'market_burn' : $type;
        $after = $ledger->changeExact($payer, Money::parse('0')->subtract($amount)->decimal(), $kind, $label . ': ' . $line);
        $destination = $recipient === null ? '0.0000' : $ledger->changeExact($recipient, $amount->decimal(), $kind, $label . ': ' . $line);
        $this->db->createCommand()->insert('economy_transfer', ['operation_id' => $operation, 'line_code' => 'market-' . $line,
            'kind' => $kind, 'source_user_id' => $payer, 'destination_user_id' => $recipient, 'amount' => $amount->decimal(),
            'source_after' => $after, 'destination_after' => $destination, 'purpose' => $label . ': ' . $line, 'created_at' => time()])->execute();
    }

    public function settle(array $market, int $user, string $direction, array $prices, int $quantity, string $operation): Money
    {
        $amount = static fn(string $key): Money => Money::parse($prices[$key])->multiply($quantity);
        $bot = (int)$market['bot_user_id'];
        if ($direction === 'sell') {
            $this->pay($bot, $user, $amount('sell_cr'), $operation, 'buyback');
            $burn = $amount('sell_burn_cr'); $this->pay($bot, null, $burn, $operation, 'buyback-fee');
        } else {
            $this->pay($user, $bot, $amount('base_cr'), $operation, 'goods');
            $this->pay($user, (int)$market['owner_user_id'], $amount('owner_cr'), $operation, 'owner');
            (new TreasuryLedger($this->db))->receivePersonalPayment($user, (int)$market['city_id'], $amount('city_cr'), $operation, 'Комиссия городского рынка', 'market_trade');
            $burn = $amount('delivery_cr'); $this->pay($user, null, $burn, $operation, 'delivery');
            $world = (int)(new \yii\db\Query())->select('root_id')->from('world_node')->where(['id'=>$market['city_id']])->scalar($this->db);
            $policy = (new NpcInfrastructure($this->db))->policy($world);
            if ($policy && (int)$policy['bot_user_id'] === $bot && (int)$policy['city_node_id'] === (int)$market['city_id']) {
                $received = $amount('base_cr');
                if ((int)$market['owner_user_id'] === $bot) $received = $received->add($amount('owner_cr'));
                (new NpcFunding($this->db))->record($policy,$user,(int)$market['city_id'],$received,$operation,'market');
            }
        }
        return $burn;
    }
}
