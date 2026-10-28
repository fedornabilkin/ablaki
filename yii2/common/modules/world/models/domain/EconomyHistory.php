<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\FinanceReadSnapshot;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Read contract over existing sources; never replays a ledger entry or copies balances. */
final class EconomyHistory
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function ownedAccounts(int $user): Query
    {
        return (new Query())->select('a.id')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')
            ->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.owner_user_id' => $user]);
    }
    public function listing(int $user, string $source, int $page, string $search): array
    {
        if (!in_array($source, ['commands', 'credits', 'items', 'transfers', 'wallet', 'health'], true) || $page < 1 || $page > 1000000 || mb_strlen($search) > 120)
            throw new GameError('INVALID_ECONOMY_FILTER', 'Проверьте фильтры истории.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $source, $page, $search) {
            $db = $this->db;
            if ($source === 'commands') { $query = (new Query())->from('game_operation')->where(['user_id' => $user]); $field = 'type'; }
            elseif ($source === 'credits') {
                $query = (new Query())->from('economy_transfer')->where(['or', ['source_user_id' => $user], ['source_account_id' => $this->ownedAccounts($user)], ['destination_account_id' => $this->ownedAccounts($user)]]);
                $field = 'kind';
            } elseif ($source === 'items') {
                $storages = (new Query())->select('id')->from('craft_storage')->where(['owner_user_id' => $user]);
                $query = (new Query())->from('inventory_movement')->where(['or', ['source_storage_id' => $storages], ['destination_storage_id' => clone $storages]]); $field = 'reason';
            } elseif ($source === 'transfers' || $source === 'wallet') {
                // Legacy rows do not carry game operation IDs. Never expose transfer passwords/hashes.
                $query = (new Query())->select(['id', 'created_at', 'credit', 'credit_up', 'type', 'comment'])->from('history_balance')->where(['user_id' => $user]); $field = 'type';
                if ($source === 'transfers') $query->andWhere(['type' => 'transfer']);
            } else { $query = (new Query())->from('world_health_event')->where(['user_id' => $user]); $field = 'kind'; }
            $labels = ['стройка' => 'build', 'строительство' => 'build', 'крафт' => 'craft', 'изготовление' => 'craft', 'перевод' => 'transfer', 'еда' => 'food', 'добыча' => 'mining', 'награда' => 'goal', 'расширение' => 'expand', 'снабжение' => 'supplies'];
            if ($search !== '') {
                $match = ['like', $field, $labels[mb_strtolower($search, 'UTF-8')] ?? $search];
                if ($source === 'commands' || $source === 'items') {
                    $found = (new Query())->select($source === 'commands' ? 'm.operation_id' : 'm.id')->from(['m' => 'inventory_movement'])
                        ->innerJoin(['i' => 'craft_item'], 'i.id=m.item_id')->where(['or', ['like', 'i.name', $search], ['like', 'i.code', $search]]);
                    $match = ['or', $match, ['in', 'id', $found]];
                }
                $query->andWhere($match);
            }
            $total = (int)(clone $query)->count('*', $db); $items = [];
            $ownedAccounts = $source === 'credits' ? array_map('intval', $this->ownedAccounts($user)->column($db)) : [];
            foreach ($query->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($db) as $row) {
                // Existing PostgreSQL history uses CHAR(50), which PDO returns padded.
                if ($source === 'transfers' || $source === 'wallet') { $row['type'] = trim((string)$row['type']); $row['comment'] = trim((string)$row['comment']); }
                $entry = ['id' => $source === 'commands' ? $row['id'] : (int)$row['id'], 'source' => $source, 'operation_id' => $source === 'commands' ? $row['id'] : ($row['operation_id'] ?? null), 'created_at' => (int)$row['created_at'],
                    'kind' => $row[$field], 'classification' => 'action', 'amount_cr' => null, 'quantity' => null, 'balance_before_cr' => null, 'balance_after_cr' => null];
                if ($source === 'credits') {
                    $out = in_array((int)$row['source_account_id'], $ownedAccounts, true) || (int)$row['source_user_id'] === $user;
                    $in = in_array((int)$row['destination_account_id'], $ownedAccounts, true);
                    $entry['amount_cr'] = Money::parse((string)$row['amount'])->decimal();
                    $entry['classification'] = $out && $in ? 'internal' : ($in ? ($row['kind'] === 'starter_goal' ? 'reward' : (in_array($row['kind'], ['order_payment', 'crop_purchase'], true) ? 'earned' : 'incoming')) : 'expense');
                    // Foreign account balances are never included, even when its payment is visible.
                    if ($in) $entry['balance_after_cr'] = $row['destination_after'] === null ? null : Money::parse((string)$row['destination_after'])->decimal();
                    elseif ($out) $entry['balance_after_cr'] = $row['source_after'] === null ? null : Money::parse((string)$row['source_after'])->decimal();
                } elseif ($source === 'items') { $entry['quantity'] = (int)$row['quantity']; $entry['classification'] = 'item_movement'; }
                elseif ($source === 'transfers' || $source === 'wallet') { $entry['classification'] = $row['type'] === 'transfer' ? 'internal_transfer' : (strpos((string)$row['credit_up'], '-') === 0 ? 'expense' : 'incoming'); $entry['amount_cr'] = (string)$row['credit_up']; $entry['balance_after_cr'] = (string)$row['credit']; }
                elseif ($source === 'health') { $entry['classification'] = 'health'; $entry['quantity'] = (int)$row['hp_after'] - (int)$row['hp_before']; }
                $entry = $this->correlate($user, $entry, $row, $ownedAccounts);
                $entry['items'] = $this->itemDetails($user, $entry, $row);
                $items[] = $entry;
            }
            return ['items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20], 'server_time' => time()];
        });
    }
    private function itemDetails(int $user, array $entry, array $row): array
    {
        if (!$entry['operation_id'] && $entry['source'] !== 'items') return [];
        $storages = (new Query())->select('id')->from('craft_storage')->where(['owner_user_id' => $user]);
        $query = (new Query())->select(['m.item_id', 'quantity' => new \yii\db\Expression('SUM([[m.quantity]])'), 'item_name' => 'i.name', 'item_code' => 'i.code', 'source_owner' => 's.owner_user_id', 'destination_owner' => 'd.owner_user_id'])
            ->from(['m' => 'inventory_movement'])->innerJoin(['i' => 'craft_item'], 'i.id=m.item_id')
            ->leftJoin(['s' => 'craft_storage'], 's.id=m.source_storage_id')->leftJoin(['d' => 'craft_storage'], 'd.id=m.destination_storage_id')
            ->where(['or', ['m.source_storage_id' => $storages], ['m.destination_storage_id' => clone $storages]]);
        if ($entry['source'] === 'items') $query->andWhere(['m.id' => $row['id']]);
        else $query->andWhere(['m.operation_id' => $entry['operation_id']]);
        $result = [];
        foreach ($query->groupBy(['m.item_id', 'i.name', 'i.code', 's.owner_user_id', 'd.owner_user_id'])->orderBy('m.item_id')->all($this->db) as $movement) {
            $from = (int)$movement['source_owner'] === $user; $to = (int)$movement['destination_owner'] === $user;
            $direction = $from && $to ? 'moved' : ($to ? 'received' : 'spent');
            $kind = (string)$entry['kind'];
            if ($direction === 'received' && (strpos($kind, 'gather') !== false || strpos($kind, 'supplies') !== false || strpos($kind, 'source') !== false || strpos($kind, 'mining') !== false)) $direction = 'collected';
            elseif ($direction === 'received' && strpos($kind, 'craft') !== false) $direction = 'created';
            elseif ($direction === 'spent' && (strpos($kind, 'order') !== false || strpos($kind, 'sell') !== false || strpos($kind, 'crop_purchase') !== false)) $direction = 'sold';
            elseif ($direction === 'received' && strpos($kind, 'purchase') !== false) $direction = 'bought';
            $key = $movement['item_id'] . ':' . $direction;
            if (!isset($result[$key])) $result[$key] = ['item_id' => (int)$movement['item_id'], 'code' => trim($movement['item_code']), 'name' => trim($movement['item_name']), 'quantity' => 0, 'direction' => $direction];
            $result[$key]['quantity'] += (int)$movement['quantity'];
        }
        return array_values($result);
    }
    private function endpoint(string $kind, $id, int $user): ?array
    {
        if (!$id) return null;
        if ($kind === 'account') {
            $row = (new Query())->select(['a.id', 's.node_id'])->from(['a' => 'economy_account'])
                ->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
                ->where(['a.id' => $id, 'n.owner_user_id' => $user])->one($this->db);
        } else $row = (new Query())->select(['id', 'node_id'])->from('craft_storage')->where(['id' => $id, 'owner_user_id' => $user])->one($this->db);
        return $row ? ['kind' => $kind, 'id' => (int)$row['id'], 'node_id' => $row['node_id'] === null ? null : (int)$row['node_id']] : ['kind' => $kind, 'id' => null, 'node_id' => null];
    }
    private function correlate(int $user, array $entry, array $row, array $owned): array
    {
        $db = $this->db;
        $entry += ['actor_user_id' => null, 'node_id' => null, 'profile_id' => null, 'profile_version' => null,
            'from' => null, 'to' => null, 'item_id' => null, 'quantity_before' => null, 'quantity_after' => null,
            'transfer_ids' => [], 'transfer_phase' => null];
        if ($entry['operation_id']) {
            $operation = $entry['source'] === 'commands' ? $row : (new Query())->from('game_operation')->where(['id' => $entry['operation_id']])->one($db);
            if ($operation) {
                $entry['actor_user_id'] = $operation['user_id'] === null ? null : (int)$operation['user_id'];
                $context = json_decode($operation['context_json'] ?? 'null', true) ?: [];
                foreach (['node_id', 'profile_id', 'profile_version'] as $key) $entry[$key] = isset($context[$key]) ? (int)$context[$key] : null;
            }
        }
        if ($entry['source'] === 'credits') {
            $entry['from'] = $row['source_user_id'] ? ['kind' => 'wallet', 'id' => (int)$row['source_user_id'] === $user ? $user : null, 'node_id' => null] : $this->endpoint('account', $row['source_account_id'], $user);
            $entry['to'] = $this->endpoint('account', $row['destination_account_id'], $user);
            $incoming = in_array((int)$row['destination_account_id'], $owned, true);
            if ($entry['balance_after_cr'] !== null) $entry['balance_before_cr'] = ($incoming
                ? Money::parse($entry['balance_after_cr'])->subtract(Money::parse($entry['amount_cr']))
                : Money::parse($entry['balance_after_cr'])->add(Money::parse($entry['amount_cr'])))->decimal();
            $entry['node_id'] = ($incoming ? $entry['to']['node_id'] : $entry['from']['node_id']) ?? $entry['node_id'];
        } elseif ($entry['source'] === 'items') {
            $entry['from'] = $this->endpoint('storage', $row['source_storage_id'], $user);
            $entry['to'] = $this->endpoint('storage', $row['destination_storage_id'], $user);
            $entry['item_id'] = (int)$row['item_id'];
            $entry['node_id'] = $entry['to']['node_id'] ?? $entry['from']['node_id'] ?? $entry['node_id'];
        } elseif ($entry['source'] === 'wallet' || $entry['source'] === 'transfers') {
            $entry['actor_user_id'] = $user;
            // Old floating-point history is displayed verbatim; only exact rows can be reconstructed.
            try { $entry['balance_before_cr'] = Money::parse((string)$row['credit'])->subtract(Money::parse((string)$row['credit_up']))->decimal(); }
            catch (\InvalidArgumentException $ignored) {}
            if ($row['type'] === 'transfer') {
                $links = (new Query())->from('credit_transfer_history')->where(['history_id' => $row['id']])->orderBy('transfer_id')->all($db);
                foreach ($links as $link) { $entry['transfer_ids'][] = (int)$link['transfer_id']; $entry['transfer_phase'] = $link['phase']; }
                if (!$links && preg_match('/^(Confirm|Delete) #([1-9][0-9]*)$/D', $row['comment'] ?? '', $match)) {
                    $entry['transfer_ids'] = [(int)$match[2]]; $entry['transfer_phase'] = $match[1] === 'Confirm' ? 'claim' : 'cancel';
                } elseif (!$links && preg_match('/^Create [0-9]+x/', $row['comment'] ?? '')) $entry['transfer_phase'] = 'create';
            }
        } elseif ($entry['source'] === 'health') {
            $entry['profile_id'] = (int)$row['profile_id']; $entry['quantity_before'] = (int)$row['hp_before']; $entry['quantity_after'] = (int)$row['hp_after'];
        }
        if ($entry['profile_id'] && !$entry['profile_version']) $entry['profile_version'] = (int)(new Query())->select('version')->from('world_economy_profile')->where(['id' => $entry['profile_id']])->scalar($db);
        if ($entry['operation_id']) {
            $accepted = (new Query())->select('profile_id')->from('world_source_work')->where(['operation_id' => $entry['operation_id'], 'status' => 'completed'])->scalar($db);
            if ($accepted) {
                $entry['profile_id'] = (int)$accepted;
                $entry['profile_version'] = (int)(new Query())->select('version')->from('world_economy_profile')->where(['id' => $accepted])->scalar($db);
            }
        }
        return $entry;
    }
}
