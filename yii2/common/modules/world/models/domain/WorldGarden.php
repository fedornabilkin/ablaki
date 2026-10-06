<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\models\domain\ExpansionPolicy;
use common\modules\world\modules\economy\models\domain\TreasuryLedger;
use common\modules\world\modules\economy\models\domain\WalletSchema;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** One paid starter garden per membership. Permanent beds; no crop simulation or minted income. */
class WorldGarden
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    private function ready(): bool { return $this->flags->capabilities()['world_write'] && $this->flags->capabilities()['storage_v2'] && WalletSchema::ready($this->db); }
    private function context(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read'); $reader = new WorldQuery($this->db, new WorldAccessPolicy($user));
        $node = $reader->node($id); $node['details'] = (array)$node['details']; $membership = null; $purchase = null;
        if ($node['type'] === 'SETTLEMENT') $place = $node;
        elseif ($node['type'] === 'PLOT' && $node['permissions']['storage']) {
            if (($node['details']['plot_kind'] ?? '') === 'campsite') {
                $membership = $this->one('world_membership', ['starter_site_id' => $id, 'user_id' => $user]);
                if ($membership) $purchase = $this->one('world_garden_purchase', ['membership_id' => $membership['id']]);
            } elseif (($node['details']['plot_kind'] ?? '') === 'garden') {
                $purchase = $this->one('world_garden_purchase', ['node_id' => $id]);
                if ($purchase) $membership = $this->one('world_membership', ['id' => $purchase['membership_id'], 'user_id' => $user]);
            }
            if (!$membership) throw new GameError('GARDEN_SITE_REQUIRED', 'Откройте свою стартовую стоянку или приобретённый огород.', 403);
            $parent = $reader->node($node['parent_id']);
            // Gardens belong to the starter estate. Keep the settlement as the
            // financial/policy scope while allowing the garden itself to sit
            // under the campsite on the world tree. Older gardens directly
            // under a settlement remain readable.
            $place = $parent['type'] === 'PLOT' && ($parent['details']['plot_kind'] ?? '') === 'campsite'
                ? $reader->node($parent['parent_id'])
                : $parent;
        } else throw new GameError('GARDEN_SITE_REQUIRED', 'Огород доступен в поселении и на собственной стоянке.', 403);
        if ($node['status'] !== 'active' || $place['type'] !== 'SETTLEMENT' || $place['status'] !== 'active' || $place['visibility'] !== 'public'
            || (new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $id])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('GARDEN_UNAVAILABLE', 'Территория огорода недоступна.');
        $owner = $this->one('world_node', ['id' => $place['id']])['owner_user_id'];
        return compact('node', 'place', 'membership', 'purchase') + ['manager' => $owner === null ? $this->access->isAdmin() : (int)$owner === $user];
    }
    public function input(array $body, string $action): array
    {
        if ($action === 'publish') {
            if (!is_string($body['name'] ?? null) || trim($body['name']) === '' || mb_strlen($body['name'], 'UTF-8') > 120) throw new GameError('INVALID_GARDEN', 'Укажите название огорода.', 422);
            $result = ['name' => trim($body['name'])];
            foreach (['price', 'base_price'] as $key) {
                try { if (!is_string($body[$key] ?? null)) throw new \InvalidArgumentException(); $amount = Money::parse($body[$key]); }
                catch (\Exception $e) { throw new GameError('INVALID_GARDEN_PRICE', 'Укажите положительные цены с точностью до четырёх знаков.', 422); }
                if ($amount->isZero() || $amount->isNegative()) throw new GameError('INVALID_GARDEN_PRICE', 'Цена должна быть положительной.', 422);
                $result[$key] = $amount->decimal();
            }
            // Every possible package must fit the exact-money range before publication.
            try { (new ExpansionPolicy())->quote(1, 1, 10, $result['base_price'], 9); }
            catch (\OverflowException $e) { throw new GameError('INVALID_GARDEN_PRICE', 'Суммарная цена грядок слишком велика.', 422); }
            return $result;
        }
        if ($action === 'withdraw') return [];
        if (!in_array($action, ['buy', 'expand'], true) || !is_bool($body['top_up'] ?? null)) throw new GameError('INVALID_GARDEN', 'Укажите согласие на пополнение бюджета.', 422);
        if ($action === 'buy') return ['top_up' => $body['top_up']];
        if (!is_int($body['quantity'] ?? null) || $body['quantity'] < 1 || $body['quantity'] > 9) throw new GameError('INVALID_GARDEN', 'Выберите от одной до девяти грядок.', 422);
        return ['top_up' => $body['top_up'], 'quantity' => $body['quantity']];
    }
    private function beds(int $garden): array
    {
        $policy = $this->one('world_expansion_policy', ['node_id' => $garden, 'kind' => 'garden_bed']);
        if (!$policy || (int)$policy['initial_open'] !== 1 || (int)$policy['place_limit'] !== 10 || !in_array($policy['curve'], ['linear', 'progressive'], true)) throw new GameError('GARDEN_STATE_INVALID', 'Права огорода требуют сверки.');
        $rows = (new Query())->select(['b.*', 'parent_id' => 'n.parent_id', 'n.status', 'entitlement_id' => 'e.id', 'policy_id' => 'e.policy_id', 'entitlement_ordinal' => 'e.ordinal'])
            ->from(['b' => 'world_bed'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[b.node_id]]')->leftJoin(['e' => 'world_expansion_entitlement'], '[[e.node_id]]=[[b.node_id]]')
            ->where(['b.garden_node_id' => $garden])->orderBy(['b.ordinal' => SORT_ASC])->limit(11)->all($this->db);
        if (count($rows) !== 10) throw new GameError('GARDEN_STATE_INVALID', 'Набор грядок требует сверки.');
        $unlocked = 0; $items = []; $locked = false;
        foreach ($rows as $i => $row) {
            $open = (int)$row['unlocked'] === 1;
            if ((int)$row['ordinal'] !== $i + 1 || (int)$row['parent_id'] !== $garden || $row['status'] !== 'active' || ($open && $locked) || $open !== ($row['entitlement_id'] !== null)
                || ($open && ((int)$row['policy_id'] !== (int)$policy['id'] || (int)$row['entitlement_ordinal'] !== $i + 1))) throw new GameError('GARDEN_STATE_INVALID', 'Последовательность прав грядок требует сверки.');
            if ($open) $unlocked++; else $locked = true;
            $quote = $i === 0 ? null : (new ExpansionPolicy())->quote(1, $i, 10, (string)$policy['base_price'], 1, $policy['curve']);
            $items[] = ['node_id' => (int)$row['node_id'], 'ordinal' => $i + 1, 'unlocked' => $open, 'price' => $quote ? $quote['unit_prices'][0]['price'] : '0.0000'];
        }
        if (!$unlocked) throw new GameError('GARDEN_STATE_INVALID', 'Первая грядка должна быть доступна.');
        return compact('policy', 'unlocked', 'items');
    }
    public function state(int $user, int $id): array
    {
        $c = $this->context($user, $id); $offer = $this->one('world_garden_offer', ['active_settlement_id' => $c['place']['id']]);
        $siteBudgetAvailable = null;
        if ($c['membership']) {
            $accounts = (new EconomyHierarchy($this->db))->accounts((int)$c['membership']['starter_site_id']);
            if (isset($accounts['budget'])) $siteBudgetAvailable = (new BudgetSpending($this->db))->available((int)$accounts['budget']['id'])->decimal();
        }
        if ($offer) $offer['can_afford'] = $siteBudgetAvailable === null ? null : Money::parse((string)$offer['price'])->compare(Money::parse($siteBudgetAvailable)) <= 0;
        $garden = null;
        if ($c['purchase']) {
            $gardenId = (int)$c['purchase']['node_id']; $ownedGarden = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($gardenId);
            if (!$ownedGarden['permissions']['storage']) throw new GameError('GARDEN_OWNER_REQUIRED', 'Финансы огорода доступны его владельцу.', 403);
            $beds = $this->beds($gardenId); $accounts = (new EconomyHierarchy($this->db))->accounts($gardenId);
            $garden = ['node_id' => $gardenId, 'unlocked' => $beds['unlocked'], 'limit' => 10, 'base_price' => Money::parse((string)$beds['policy']['base_price'])->decimal(), 'beds' => $beds['items'],
                'available_budget' => isset($accounts['budget']) ? (new BudgetSpending($this->db))->available((int)$accounts['budget']['id'])->decimal() : '0.0000'];
        }
        return ['node_id' => $id, 'settlement_id' => $c['place']['id'], 'settlement_name' => $c['place']['name'],
            'offer' => $offer ? ['id' => (int)$offer['id'], 'name' => $offer['name'], 'price' => Money::parse((string)$offer['price'])->decimal(), 'base_price' => Money::parse((string)$offer['base_price'])->decimal(), 'can_afford' => $offer['can_afford']] : null,
            'site_budget_available' => $siteBudgetAvailable,
            'garden' => $garden, 'can_publish' => $c['node']['type'] === 'SETTLEMENT' && $c['manager'] && $this->flags->capabilities()['world_write'],
            'can_buy' => $c['membership'] !== null && !$garden && $offer !== null && $this->ready(),
            'can_expand' => $garden && $garden['node_id'] === $id && $garden['unlocked'] < 10 && $this->ready(),
            'cultivation_enabled' => $garden !== null && $this->flags->capabilities()['world_write'] && $this->flags->capabilities()['storage_v2']
                && $this->db->schema->getTableSchema('world_crop_revision') !== null
                && (new Query())->from('world_crop_revision')->where(['status' => 'published'])->exists($this->db),
            'server_time' => time()];
    }
    private function prepare(int $user, array $input, string $action): array
    {
        $validated = $this->input($input, $action); $c = $this->context($user, $input['node_id']);
        $node = $c['node']; $place = $c['place']; $terms = ['node_id' => $node['id']] + $validated;
        $revisions = ['node:' . $node['id'] => $node['revision'], 'node:' . $place['id'] => $place['revision']];
        $offer = $this->one('world_garden_offer', ['active_settlement_id' => $place['id']]);
        if ($action === 'publish' || $action === 'withdraw') {
            if ($node['type'] !== 'SETTLEMENT' || !$c['manager']) throw new GameError('GARDEN_MANAGEMENT_FORBIDDEN', 'Цены публикует владелец поселения или администратор системного поселения.', 403);
            if (isset($input['expected_offer_id']) && (int)($offer['id'] ?? 0) !== $input['expected_offer_id']) throw new GameError('GARDEN_OFFER_CHANGED', 'Предложение изменилось. Откройте актуальную запись.');
            if (isset($input['admin_reason']) && (!is_string($input['admin_reason']) || trim($input['admin_reason']) === '' || mb_strlen($input['admin_reason'], 'UTF-8') > 255)) throw new GameError('INVALID_REASON', 'Укажите причину изменения.', 422);
            if ($action === 'withdraw' && !$offer) throw new GameError('GARDEN_OFFER_UNAVAILABLE', 'Предложение уже снято.');
            $terms['previous_offer_id'] = $offer ? (int)$offer['id'] : null;
            return $c + compact('terms', 'revisions');
        }
        $this->flags->requireFlag('storage_v2'); WalletSchema::requireReady($this->db);
        if (!$c['membership']) throw new GameError('GARDEN_SITE_REQUIRED', 'Покупайте из собственной стоянки или огорода.', 403);
        if ($action === 'buy') {
            if ($c['purchase']) throw new GameError('GARDEN_ALREADY_OWNED', 'Стартовый огород уже приобретён; его права сохраняются.');
            if (!$offer) throw new GameError('GARDEN_OFFER_UNAVAILABLE', 'Поселение ещё не опубликовало стоимость огорода.');
            $terms += ['offer_id' => (int)$offer['id'], 'name' => $offer['name'], 'base_price' => Money::parse((string)$offer['base_price'])->decimal(), 'total' => Money::parse((string)$offer['price'])->decimal(), 'initial_open' => 1, 'limit' => 10];
        } else {
            if (!$c['purchase'] || (int)$c['purchase']['node_id'] !== $node['id']) throw new GameError('GARDEN_SITE_REQUIRED', 'Откройте приобретённый огород.');
            $beds = $this->beds($node['id']); $policy = $beds['policy'];
            $terms += (new ExpansionPolicy())->quote(1, $beds['unlocked'], 10, (string)$policy['base_price'], $input['quantity'], (string)$policy['curve']);
            $terms += ['policy_id' => (int)$policy['id'], 'policy_revision' => (int)$policy['revision'], 'base_price' => Money::parse((string)$policy['base_price'])->decimal()];
        }
        $accounts = (new EconomyHierarchy($this->db))->accounts($node['id']); $budget = $accounts['budget'] ?? null;
        $spend = new BudgetSpending($this->db); $available = $budget ? $spend->available((int)$budget['id']) : Money::parse('0'); $total = Money::parse($terms['total']);
        $gap = $total->compare($available) > 0 ? $total->subtract($available) : Money::parse('0');
        if (!$gap->isZero() && !$input['top_up']) throw new GameError('INSUFFICIENT_BUDGET', 'Пополните бюджет или явно выберите пополнение недостающей суммы с личного баланса.');
        $terms += ['source_node_id' => $node['id'], 'source_budget_id' => $budget ? (int)$budget['id'] : null, 'available_before' => $available->decimal(), 'personal_charge' => $gap->decimal(), 'wallet_before' => null, 'wallet_after' => null];
        if (!$gap->isZero()) {
            $person = $this->one('persone', ['user_id' => $user]);
            if (!$person || Money::parse((string)$person['credit'])->compare($gap) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'Личных кредитов не хватает для пополнения бюджета.');
            $terms['wallet_before'] = Money::parse((string)$person['credit'])->decimal(); $terms['wallet_after'] = Money::parse($terms['wallet_before'])->subtract($gap)->decimal();
            Money::parse((string)($budget['amount'] ?? '0'))->add($gap);
        }
        if ($budget) { $revisions['account:' . $budget['id']] = (int)$budget['revision']; $spend->allocation((int)$budget['id'], $total); }
        $recipientPolicy = (new TreasuryLedger($this->db))->published($place['id']); $recipient = (new EconomyHierarchy($this->db))->accounts($place['id']);
        if (!isset($recipient['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна поселения недоступна.');
        Money::parse((string)$recipient['treasury']['amount'])->add($total);
        $terms += ['recipient_node_id' => $place['id'], 'recipient_name' => $place['name'], 'recipient_account_id' => (int)$recipient['treasury']['id'], 'recipient_policy_id' => $recipientPolicy['history_id']];
        return $c + compact('terms', 'revisions');
    }
    private function prepared(int $user, array $input, string $action): array
    {
        try { return $this->prepare($user, $input, $action); }
        catch (\OverflowException $e) { throw new GameError('GARDEN_AMOUNT_LIMIT', 'Сумма операции превышает допустимый предел.', 422); }
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.garden.' . $action, $input, function () use ($user, $input, $action) { return $this->prepared($user, $input, $action); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.garden.' . $action, $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $action, $bus) {
            $p = $this->prepared($user, $payload, $action);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('GARDEN_CHANGED', 'Условия изменились. Повторите расчёт.');
            $tree = new WorldTree($this->db); $changed = [$payload['node_id'], $p['place']['id']]; $now = time(); $publishedId = null;
            if ($action === 'publish' || $action === 'withdraw') {
                if ($terms['previous_offer_id'] !== null && $this->db->createCommand()->update('world_garden_offer', ['active_settlement_id' => null], ['id' => $terms['previous_offer_id'], 'active_settlement_id' => $p['place']['id']])->execute() !== 1) throw new \RuntimeException('Garden offer changed.');
                if ($action === 'publish') $this->db->createCommand()->insert('world_garden_offer', ['settlement_id' => $p['place']['id'], 'active_settlement_id' => $p['place']['id'], 'name' => $payload['name'], 'price' => $payload['price'], 'base_price' => $payload['base_price'], 'operation_id' => $operation, 'created_at' => $now])->execute();
                if ($action === 'publish') $publishedId = (int)$this->db->getLastInsertID();
            } else {
                $funding = null; $gap = Money::parse($terms['personal_charge']);
                if (!$gap->isZero()) $funding = (new BudgetFunding($this->db))->contribute($user, $payload['node_id'], $gap, 'Пополнение для покупки огорода или грядок', $operation);
                $accounts = (new EconomyHierarchy($this->db))->accounts($payload['node_id']); $spend = new BudgetSpending($this->db); $total = Money::parse($terms['total']);
                $hold = $spend->reserve((int)$accounts['budget']['id'], $total, $action === 'buy' ? 'Покупка огорода' : 'Открытие грядок', $operation);
                $transfer = $spend->pay($hold, $terms['recipient_node_id'], $total, $operation, $action === 'buy' ? 'garden_purchase' : 'garden_expansion');
                if ($action === 'buy') {
                    $code = 'garden-' . $operation;
                    $garden = $tree->create(['code' => $code, 'slug' => $code, 'node_type' => 'PLOT', 'name' => $terms['name'], 'parent_id' => $p['node']['id'], 'owner_user_id' => $user, 'visibility' => 'private'], ['plot_kind' => 'garden', 'area' => 10, 'allow_building' => 0]);
                    $gardenId = (int)$garden['id'];
                    GardenHarvest::provision($this->db, $gardenId, $user);
                    $this->db->createCommand()->insert('world_garden_purchase', ['node_id' => $gardenId, 'membership_id' => $p['membership']['id'], 'offer_id' => $terms['offer_id'], 'transfer_id' => $transfer, 'operation_id' => $operation, 'terms_json' => CanonicalJson::encode($terms), 'created_at' => $now])->execute();
                    $this->db->createCommand()->insert('world_expansion_policy', ['node_id' => $gardenId, 'kind' => 'garden_bed', 'initial_open' => 1, 'place_limit' => 10, 'base_price' => $terms['base_price'], 'curve' => 'progressive', 'operation_id' => $operation])->execute(); $policyId = (int)$this->db->getLastInsertID();
                    for ($ordinal = 1; $ordinal <= 10; $ordinal++) {
                        $bed = $tree->create(['code' => $code . '-bed-' . $ordinal, 'slug' => 'bed-' . $ordinal, 'node_type' => 'BED', 'name' => 'Грядка ' . $ordinal, 'parent_id' => $gardenId, 'owner_user_id' => $user, 'visibility' => 'private', 'position' => $ordinal, 'position_x' => ($ordinal - 1) % 5 - 2, 'position_y' => $ordinal <= 5 ? 0 : -1], ['garden_node_id' => $gardenId, 'ordinal' => $ordinal, 'unlocked' => $ordinal === 1 ? 1 : 0]);
                        (new EconomyHierarchy($this->db))->provision((int)$bed['id'], $operation);
                        if ($ordinal === 1) $this->entitle($policyId, (int)$bed['id'], 1, '0.0000', 1, null, $operation, $now);
                        $changed[] = (int)$bed['id'];
                    }
                } else {
                    $gardenId = $payload['node_id'];
                    foreach ($terms['unit_prices'] as $unit) {
                        $bed = $this->one('world_bed', ['garden_node_id' => $gardenId, 'ordinal' => $unit['ordinal'], 'unlocked' => 0]);
                        if (!$bed || $this->db->createCommand()->update('world_bed', ['unlocked' => 1], ['node_id' => $bed['node_id'], 'unlocked' => 0])->execute() !== 1) throw new \RuntimeException('Bed opening failed.');
                        $bedNode = $tree->get((int)$bed['node_id']);
                        $cell = ['parent_id' => $gardenId, 'x' => (int)$bedNode['position_x'], 'y' => (int)$bedNode['position_y']];
                        if ((new Query())->from('world_map_cell')->where($cell)->exists($this->db))
                            $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'price' => $unit['price'], 'operation_id' => $operation, 'updated_at' => $now], $cell)->execute();
                        else $this->db->createCommand()->insert('world_map_cell', $cell + ['state' => 'open', 'price' => $unit['price'], 'operation_id' => $operation, 'created_at' => $now, 'updated_at' => $now])->execute();
                        $this->entitle($terms['policy_id'], (int)$bed['node_id'], $unit['ordinal'], $unit['price'], $terms['policy_revision'], $transfer, $operation, $now); $changed[] = (int)$bed['node_id'];
                    }
                }
                $changed[] = $gardenId;
                if ($funding) $bus->emit($operation, $user, 'economy.invested', ['node_id' => $payload['node_id'], 'transfer_id' => $funding['transfer_id'], 'amount' => $gap->decimal()]);
            }
            $changed = array_values(array_unique($changed));
            $ancestorIds = (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $changed])->distinct()->column($this->db);
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $ancestorIds])->execute();
            $result = ['changed_node_ids' => $changed];
            if ($publishedId !== null) $result['offer_id'] = $publishedId;
            $tree->audit($user, 'world.garden.' . $action, $payload['admin_reason'] ?? 'Огород и постоянные права грядок', $terms, $result + ['id' => $payload['node_id']], $operation);
            $bus->emit($operation, $user, 'world.garden.' . $action, $result);
            return $result;
        });
    }
    private function entitle(int $policy, int $node, int $ordinal, string $price, int $revision, ?int $transfer, string $operation, int $now): void
    {
        $this->db->createCommand()->insert('world_expansion_entitlement', ['policy_id' => $policy, 'node_id' => $node, 'ordinal' => $ordinal, 'price' => $price, 'policy_revision' => $revision, 'transfer_id' => $transfer, 'operation_id' => $operation, 'created_at' => $now])->execute();
    }
}
