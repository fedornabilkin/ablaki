<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\FinanceReadSnapshot;
use common\modules\world\modules\economy\value\Money;
use yii\db\Connection;
use yii\db\Query;

/** Operational read model. Cohorts label observations and never grant admission. */
final class EconomyReport
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private static function day(int $at): int { return (int)(new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Ymd'); }
    private function total(string $table, string $field, array $where = []): string {
        $total = Money::parse('0');
        foreach ((new Query())->select($field)->from($table)->where($where)->column($this->db) as $value) $total = $total->add(Money::parse((string)$value));
        return $total->decimal();
    }
    private function finances(int $user, int $world): array
    {
        $accounts = (new Query())->select('a.*')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')
            ->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.owner_user_id' => $user, 'n.root_id' => $world])->all($this->db);
        $ids = array_map('intval', array_column($accounts, 'id'));
        $totals = array_fill_keys(['earned', 'rewards', 'other_incoming', 'expenses', 'personal_investment', 'internal', 'reserved'], Money::parse('0'));
        foreach ($accounts as $account) $totals['reserved'] = $totals['reserved']->add(Money::parse((string)$account['reserved']));
        foreach ((new Query())->from('economy_transfer')->where(['or', ['source_account_id' => $ids], ['destination_account_id' => $ids]])->each(500, $this->db) as $row) {
            $out = in_array((int)$row['source_account_id'], $ids, true); $in = in_array((int)$row['destination_account_id'], $ids, true);
            $key = $out && $in ? 'internal' : ($out ? 'expenses' : 'other_incoming');
            if (!$out && $in) {
                if ((int)$row['source_user_id'] === $user) $key = 'personal_investment';
                elseif ($row['kind'] === 'starter_goal') $key = 'rewards';
                elseif (in_array($row['kind'], ['order_payment', 'crop_purchase'], true)) $key = 'earned';
            }
            $totals[$key] = $totals[$key]->add(Money::parse((string)$row['amount']));
        }
        $result = []; foreach ($totals as $key => $value) $result[$key . '_cr'] = $value->decimal();
        return $result + ['scope' => 'current_owned_world_accounts', 'period' => 'lifetime'];
    }
    public function report(string $cohort = '', int $afterUser = 0, int $limit = 100): array
    {
        if (mb_strlen($cohort) > 80 || $afterUser < 0 || $limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Invalid report filters.');
        return (new FinanceReadSnapshot($this->db))->run(function () use ($cohort, $afterUser, $limit) {
            $world = EconomyRuntime::world($this->db); $profile = EconomyRuntime::current($this->db); $now = time();
            $query = (new Query())->select(['m.user_id', 'm.joined_at', 'p.cohort'])->from(['m' => 'world_membership'])
                ->leftJoin(['p' => 'world_test_participant'], 'p.user_id=m.user_id')->where(['m.world_id' => $world])->andWhere(['>', 'm.user_id', $afterUser]);
            if ($cohort !== '') $query->andWhere(['p.cohort' => $cohort]);
            $total = (int)(clone $query)->count('*', $this->db); $members = $query->orderBy('m.user_id')->limit($limit)->all($this->db); $users = [];
            foreach ($members as $member) {
                $user = (int)$member['user_id']; $joined = (int)$member['joined_at']; $first = []; $days = [];
                foreach ((new Query())->from('world_economy_fact_event')->where(['world_id' => $world, 'user_id' => $user])->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->each(500, $this->db) as $event) {
                    $code = $event['code']; if (!isset($first[$code])) $first[$code] = ['at' => (int)$event['created_at'], 'seconds_since_join' => max(0, (int)$event['created_at'] - $joined), 'operation_id' => $event['operation_id'], 'profile_id' => (int)$event['profile_id']];
                    $days[self::day((int)$event['created_at'])] = true;
                }
                $retention = [];
                foreach ([1, 7, 30] as $offset) {
                    $target = (new \DateTimeImmutable('@' . $joined))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('+' . $offset . ' days');
                    $retention['D' . $offset] = ['eligible' => $target->modify('+1 day')->getTimestamp() <= $now, 'returned' => isset($days[(int)$target->format('Ymd')])];
                }
                $personal = (new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($this->db);
                $users[] = ['user_id' => $user, 'cohort' => $member['cohort'], 'joined_at' => $joined, 'admitted_now' => EconomyRuntime::admission($this->db, $user)['allowed'],
                    'first_facts' => $first, 'active_days' => count($days), 'retention' => $retention, 'personal_credit_cr' => $personal === false ? null : Money::parse((string)$personal)->decimal(),
                    'transfer_net_cr' => $this->total('history_balance', 'credit_up', ['user_id' => $user, 'type' => 'transfer']),
                    'finances' => $this->finances($user, $world),
                    'goal_claims' => (int)(new Query())->from('world_goal_claim')->where(['world_id' => $world, 'user_id' => $user])->count('*', $this->db)];
            }
            $accounts = (new Query())->select('a.*')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.root_id' => $world])->all($this->db);
            $totals = []; $issues = [];
            foreach ($accounts as $account) {
                $amount = Money::parse((string)$account['amount']); $reserved = Money::parse((string)$account['reserved']);
                if ($amount->isNegative() || $reserved->isNegative() || $reserved->compare($amount) > 0) $issues[] = ['code' => 'ACCOUNT_RESERVE_INVALID', 'account_id' => (int)$account['id']];
                $role = $account['role']; $totals[$role] = ($totals[$role] ?? Money::parse('0'))->add($amount);
            }
            foreach ($totals as $role => $amount) $totals[$role] = $amount->decimal();
            return ['read_only' => true, 'generated_at' => $now, 'world_id' => $world, 'profile_version' => $profile ? $profile['version'] : null,
                'cohort' => $cohort, 'users' => $users, 'remaining_users' => max(0, $total - count($users)), 'next_after_user' => $users ? end($users)['user_id'] : null,
                'account_totals_cr' => $totals, 'issues' => $issues, 'notes' => ['retention_uses_moscow_calendar_days_and_server_facts', 'unfinished_observation_windows_are_not_failures',
                    'transfers_are_not_earned_income', 'reserves_are_part_of_account_amount_not_additional_money', 'loss_accounts_retain_explicit_treasury_losses', 'page_is_not_a_global_conservation_certificate']];
        });
    }
}
