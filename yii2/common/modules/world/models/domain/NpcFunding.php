<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\economy\models\domain\{BudgetFunding, BudgetSpending, EconomyHierarchy};
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\{CanonicalJson, Locks};
use yii\db\{Connection, Expression, Query};

/** Sale-backed funding authority, separate from the bot's existing wallet. Never mints credits. */
final class NpcFunding
{
    public function __construct(private readonly Connection $db) {}
    public function ready(): bool { return $this->db->schema->getTableSchema('world_npc_funding') !== null; }
    private function state(int $site): array
    {
        if(!$this->db->getTransaction()) throw new \LogicException('NPC funding requires the world transaction.');
        $locks = new Locks($this->db);
        // Every caller already holds the standard world command/worker locks.
        $state = $locks->row('world_npc_funding', ['site_id' => $site]);
        if (!$state) {
            $this->db->createCommand()->insert('world_npc_funding', ['site_id' => $site])->execute();
            $state = $locks->row('world_npc_funding', ['site_id' => $site]);
        }
        return $state;
    }
    public function record(array $policy, int $buyer, int $node, Money $received, string $operation, string $channel, ?int $at = null): void
    {
        if (!$this->ready() || $buyer === (int)$policy['bot_user_id'] || $received->isZero()) return;
        if ($received->isNegative() || !in_array($channel, ['source','warehouse','market'], true)) throw new \InvalidArgumentException('Invalid NPC sale.');
        $state = $this->state((int)$policy['site_node_id']);
        if ((new Query())->from('world_npc_revenue')->where(['operation_id'=>$operation,'channel'=>$channel])->exists($this->db)) return;
        $portion = $received->portion((int)$state['share_bps'], (int)$state['carry']);
        $this->db->createCommand()->insert('world_npc_revenue', ['site_id'=>$state['site_id'], 'buyer_id'=>$buyer, 'node_id'=>$node,
            'operation_id'=>$operation, 'channel'=>$channel, 'amount_cr'=>$received->decimal(), 'eligible_cr'=>$portion['amount']->decimal(),
            'share_bps'=>$state['share_bps'], 'created_at'=>$at ?? time()])->execute();
        $this->db->createCommand()->update('world_npc_funding', [
            'sales_cr'=>Money::parse((string)$state['sales_cr'])->add($received)->decimal(),
            'eligible_cr'=>Money::parse((string)$state['eligible_cr'])->add($portion['amount'])->decimal(),
            'carry'=>$portion['carry'], 'revision'=>new Expression('[[revision]]+1'),
        ], ['id'=>$state['id']])->execute();
    }
    public function refill(array $policy, array $nodes, string $operation, ?int $at = null): array
    {
        $now = $at ?? time(); $state = $this->state((int)$policy['site_node_id']);
        $previous = (new Query())->from('world_npc_funding_run')->where(['operation_id'=>$operation])->one($this->db);
        if ($previous) return $previous;
        if (!(int)$state['enabled'] || (int)$state['last_refill_at'] + max(3600,(int)$state['interval_seconds']) > $now) return ['reason'=>'not_due','amount_cr'=>'0.0000'];
        $day = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $spent = Money::parse($state['day_key'] === $day ? (string)$state['day_spent_cr'] : '0');
        $limit = Money::parse((string)$state['daily_limit_cr']);
        $remaining = $limit->compare($spent) > 0 ? $limit->subtract($spent) : Money::parse('0');
        $eligible = Money::parse((string)$state['eligible_cr']);
        $wallet = Money::parse((string)((new Query())->select('credit')->from('persone')->where(['user_id'=>$policy['bot_user_id']])->scalar($this->db) ?: '0'));
        $allowed = self::maximum(Money::parse('0'),self::minimum($remaining, self::minimum($eligible, $wallet)));
        $total = Money::parse('0'); $allocations = []; $targets = [];
        $spending = new BudgetSpending($this->db); $hierarchy = new EconomyHierarchy($this->db);
        foreach ($nodes as $node) {
            if (NpcInfrastructure::role($node) !== 'source' || !NodeAvailability::activePath($this->db,(int)$node['id'])) continue;
            $place = Node::findOne((int)$node['id']);
            $account = $hierarchy->provision((int)$node['id'], $operation)['budget'];
            $targets[] = ['id'=>(int)$node['id'], 'available'=>$spending->available((int)$account['id']),
                'wage'=>Money::parse(HiredExtraction::rates($place)['reward_cr'])];
        }
        // One payable batch per source first, then equal shares of the remaining allowance.
        usort($targets, static fn($a,$b) => $a['available']->compare($b['available']) ?: $a['id'] <=> $b['id']);
        foreach ([0,1] as $pass) foreach ($targets as $index => &$target) {
            $left = $allowed->subtract($total);
            if ($left->isZero()) break;
            $goal = $pass === 0 ? $target['wage'] : self::maximum($target['wage'], Money::parse((string)$state['target_cr']));
            if ($target['available']->compare($goal) >= 0) continue;
            $amount = self::minimum($goal->subtract($target['available']), $left);
            if ($pass === 0 && $amount->compare($goal->subtract($target['available'])) < 0) continue;
            if ($pass === 1) $amount = self::minimum($amount, $left->ratioFloor(1, count($targets)-$index));
            if ($amount->isZero()) continue;
            (new BudgetFunding($this->db))->contribute((int)$policy['bot_user_id'], $target['id'], $amount, 'Почасовое пополнение NPC из выручки', $operation);
            $target['available'] = $target['available']->add($amount); $total = $total->add($amount);
            $allocations[$target['id']] = Money::parse($allocations[$target['id']] ?? '0')->add($amount)->decimal();
        }
        unset($target);
        $pending = $eligible->subtract($total);
        $reason = !$total->isZero() ? 'funded' : ($eligible->isZero() ? 'no_revenue' : ($remaining->isZero() ? 'daily_limit' : ($wallet->isZero() ? 'no_cash' : 'targets_funded')));
        $this->db->createCommand()->update('world_npc_funding', ['eligible_cr'=>$pending->decimal(), 'funded_cr'=>Money::parse((string)$state['funded_cr'])->add($total)->decimal(),
            'day_spent_cr'=>$spent->add($total)->decimal(), 'day_key'=>$day, 'last_refill_at'=>$now, 'revision'=>new Expression('[[revision]]+1')], ['id'=>$state['id']])->execute();
        $run = ['site_id'=>$state['site_id'], 'operation_id'=>$operation, 'amount_cr'=>$total->decimal(), 'remaining_cr'=>$pending->decimal(),
            'reason'=>$reason,'allocations_json'=>CanonicalJson::encode($allocations), 'created_at'=>$now];
        $this->db->createCommand()->insert('world_npc_funding_run',$run)->execute();
        return $run;
    }
    private static function minimum(Money $a, Money $b): Money { return $a->compare($b) < 0 ? $a : $b; }
    private static function maximum(Money $a, Money $b): Money { return $a->compare($b) > 0 ? $a : $b; }
}
