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

/** Equipment places in purchased premises. The shared policy prices rights, never equipment. */
class WorldEquipmentExpansion
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    public static function terms(array $config): array
    {
        $initial = $config['slots'] ?? null; $limit = $config['expansion_limit'] ?? $initial; $base = $config['expansion_base_price'] ?? null;
        $sleepingArea = ($config['kind'] ?? '') === 'house' ? 1 : 0;
        if (!is_int($initial) || !is_int($limit) || !is_int($config['area'] ?? null) || $initial < 1 || $limit < $initial || $limit + $sleepingArea > $config['area'] || $config['area'] > 4) throw new GameError('INVALID_EXPANSION', 'Места оборудования должны помещаться в доступной площади. В доме одна единица площади занята койкой.', 422);
        if ($limit === $initial) {
            if ($base !== null) throw new GameError('INVALID_EXPANSION', 'У фиксированного помещения цена расширения не задаётся.', 422);
            return ['expansion_limit' => $limit, 'expansion_base_price' => null];
        }
        try {
            if (!is_string($base)) throw new \InvalidArgumentException();
            $money = Money::parse($base);
            (new ExpansionPolicy())->quote($initial, $initial, $limit, $money->decimal(), $limit - $initial);
        } catch (\Exception $e) { throw new GameError('INVALID_EXPANSION', 'Укажите положительную базовую цену расширения в допустимых пределах.', 422); }
        return ['expansion_limit' => $limit, 'expansion_base_price' => $money->decimal()];
    }
    /** Called only inside the original paid premises purchase. Old purchases are not reinterpreted. */
    public function initialize(int $room, int $storage, int $seller, int $template, array $config, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Purchase transaction required.');
        $extra = self::terms($config); $initial = $config['slots'];
        if ($extra['expansion_limit'] === $initial) return;
        $this->db->createCommand()->insert('world_expansion_policy', ['node_id' => $room, 'kind' => 'equipment_place', 'initial_open' => $initial, 'place_limit' => $extra['expansion_limit'], 'base_price' => $extra['expansion_base_price'], 'curve' => 'progressive', 'operation_id' => $operation])->execute(); $policy = (int)$this->db->getLastInsertID();
        $this->db->createCommand()->insert('world_equipment_expansion', ['policy_id' => $policy, 'storage_id' => $storage, 'recipient_node_id' => $seller, 'template_revision_id' => $template])->execute();
        for ($position = 1; $position <= $extra['expansion_limit']; $position++) {
            if ($position > $initial) $this->db->createCommand()->insert('world_slot', ['storage_id' => $storage, 'code' => 'equipment-' . $position, 'position' => $position, 'slot_type' => 'equipment', 'size' => 1, 'exposure_class' => $config['exposure_class'], 'compatibility_json' => '{}', 'status' => 'locked'])->execute();
            else {
                $slot = $this->one('world_slot', ['storage_id' => $storage, 'position' => $position]);
                if (!$slot) throw new \LogicException('Included equipment place missing.');
                $this->entitle($policy, $storage, $position, '0.0000', 1, null, $operation);
            }
        }
    }
    private function context(int $user, int $node): array
    {
        $this->flags->requireFlag('world_read');
        $room = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node); $room['details'] = (array)$room['details'];
        if ($room['type'] !== 'ROOM' || !$room['permissions']['storage']) throw new GameError('EXPANSION_OWNER_REQUIRED', 'Откройте собственное помещение.', 403);
        $purchase = $this->one('world_premises_purchase', ['room_id' => $node, 'user_id' => $user]);
        $policy = $this->one('world_expansion_policy', ['node_id' => $node, 'kind' => 'equipment_place']);
        $active = !(new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $node])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)
            && !(new Query())->from(['b' => 'world_building'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[b.node_id]]')->where(['c.descendant_id' => $node])->andWhere(['or', ['<>', 'b.operational_status', 'active'], ['<', 'b.condition', 1]])->exists($this->db);
        if (!$policy) return compact('room', 'active') + ['policy' => null];
        $binding = $this->one('world_equipment_expansion', ['policy_id' => $policy['id']]);
        $storage = $binding ? $this->one('craft_storage', ['id' => $binding['storage_id'], 'node_id' => $node, 'kind' => 'placement', 'owner_user_id' => $user, 'status' => 'active']) : null;
        $sleepingArea = $this->one('world_housing_place', ['room_id' => $node]) ? 1 : 0;
        if (!$purchase || !$storage || !in_array($policy['curve'], ['linear', 'progressive'], true) || (int)$policy['initial_open'] < 1 || (int)$policy['place_limit'] <= (int)$policy['initial_open'] || (int)$policy['place_limit'] > 4 || (int)$policy['place_limit'] + $sleepingArea > (int)$room['details']['area']) throw new GameError('EXPANSION_STATE_INVALID', 'Права расширения требуют сверки.');
        $offer = $this->one('world_premises_offer', ['id' => $purchase['offer_id']]);
        if (!$offer || (int)$offer['settlement_id'] !== (int)$binding['recipient_node_id'] || (int)$offer['template_revision_id'] !== (int)$binding['template_revision_id']) throw new GameError('EXPANSION_STATE_INVALID', 'Условия покупки требуют сверки.');
        $slots = (new Query())->select(['s.*', 'entitlement_id' => 'e.id', 'policy_id' => 'e.policy_id', 'ordinal' => 'e.position'])->from(['s' => 'world_slot'])
            ->leftJoin(['e' => 'world_slot_entitlement'], '[[e.storage_id]]=[[s.storage_id]] AND [[e.position]]=[[s.position]]')->where(['s.storage_id' => $storage['id']])->orderBy(['s.position' => SORT_ASC])->limit(5)->all($this->db);
        if (count($slots) !== (int)$policy['place_limit']) throw new GameError('EXPANSION_STATE_INVALID', 'Набор мест требует сверки.');
        $unlocked = 0; $locked = false; $items = [];
        foreach ($slots as $i => $slot) {
            $open = $slot['status'] === 'active';
            if ((int)$slot['position'] !== $i + 1 || !in_array($slot['status'], ['active', 'locked'], true) || $slot['slot_type'] !== 'equipment' || (int)$slot['size'] !== 1 || $slot['exposure_class'] !== $room['details']['exposure_class']
                || ($open && $locked) || $open !== ($slot['entitlement_id'] !== null) || ($open && ((int)$slot['policy_id'] !== (int)$policy['id'] || (int)$slot['ordinal'] !== $i + 1))) throw new GameError('EXPANSION_STATE_INVALID', 'Последовательность купленных мест требует сверки.');
            if ($open) $unlocked++; else $locked = true;
            $ordinal = $i + 1; $extra = $ordinal <= (int)$policy['initial_open'] ? '0.0000' : (new ExpansionPolicy())->quote((int)$policy['initial_open'], $ordinal - 1, (int)$policy['place_limit'], (string)$policy['base_price'], 1, (string)$policy['curve'])['unit_prices'][0]['price'];
            $items[] = ['position' => $ordinal, 'unlocked' => $open, 'price' => $extra];
        }
        if ($unlocked < (int)$policy['initial_open'] || $unlocked !== (int)$storage['capacity']) throw new GameError('EXPANSION_STATE_INVALID', 'Вместимость не соответствует купленным правам.');
        // A locked position must not silently legitimise misplaced items when bought.
        if ((new Query())->from('craft_inventory')->where(['storage_id' => $storage['id']])->andWhere(['>', 'item_quantity', 0])->andWhere(['or', ['slot' => null], ['<', 'slot', 1], ['>', 'slot', $unlocked]])->exists($this->db)) throw new GameError('EXPANSION_STATE_INVALID', 'Вещи за пределами купленных мест требуют восстановления.');
        return compact('room', 'active', 'policy', 'binding', 'storage', 'unlocked', 'items');
    }
    public function state(int $user, int $node): array
    {
        $c = $this->context($user, $node); $accounts = (new EconomyHierarchy($this->db))->accounts($node);
        $policy = $c['policy'];
        return ['node_id' => $node, 'supported' => $policy !== null, 'writable' => $policy !== null && $c['active'] && $this->flags->capabilities()['world_write'] && $this->flags->capabilities()['storage_v2'] && WalletSchema::ready($this->db),
            'expansion' => $policy ? ['initial_open' => (int)$policy['initial_open'], 'unlocked' => $c['unlocked'], 'limit' => (int)$policy['place_limit'], 'base_price' => Money::parse((string)$policy['base_price'])->decimal(), 'places' => $c['items'], 'storage_id' => (int)$c['storage']['id'], 'exposure_class' => $c['room']['details']['exposure_class']] : null,
            'available_budget' => isset($accounts['budget']) ? (new BudgetSpending($this->db))->available((int)$accounts['budget']['id'])->decimal() : '0.0000', 'server_time' => time()];
    }
    public function input(array $body): array
    {
        if (!is_int($body['quantity'] ?? null) || $body['quantity'] < 1 || $body['quantity'] > 3 || !is_bool($body['top_up'] ?? null)) throw new GameError('INVALID_EXPANSION', 'Выберите число мест и согласие на пополнение бюджета.', 422);
        return ['quantity' => $body['quantity'], 'top_up' => $body['top_up']];
    }
    private function prepare(int $user, array $input): array
    {
        $this->input($input); $this->flags->requireFlag('storage_v2'); WalletSchema::requireReady($this->db); $c = $this->context($user, $input['node_id']);
        if (!$c['policy'] || !$c['active']) throw new GameError('EXPANSION_UNAVAILABLE', 'Расширение этого помещения сейчас недоступно.');
        $policy = $c['policy']; $terms = $input + (new ExpansionPolicy())->quote((int)$policy['initial_open'], $c['unlocked'], (int)$policy['place_limit'], (string)$policy['base_price'], $input['quantity'], (string)$policy['curve']);
        $recipient = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node((int)$c['binding']['recipient_node_id']);
        if ($recipient['type'] !== 'SETTLEMENT' || $recipient['status'] !== 'active' || $recipient['root_id'] !== $c['room']['root_id']
            || (new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $recipient['id']])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('EXPANSION_RECIPIENT_UNAVAILABLE', 'Поселение-поставщик недоступно.');
        $accounts = (new EconomyHierarchy($this->db))->accounts($input['node_id']); $budget = $accounts['budget'] ?? null; $spend = new BudgetSpending($this->db);
        $available = $budget ? $spend->available((int)$budget['id']) : Money::parse('0'); $total = Money::parse($terms['total']);
        $gap = $total->compare($available) > 0 ? $total->subtract($available) : Money::parse('0');
        if (!$gap->isZero() && !$input['top_up']) throw new GameError('INSUFFICIENT_BUDGET', 'Пополните бюджет комнаты или явно выберите взнос недостающей суммы с личного баланса.');
        $terms += ['policy_id' => (int)$policy['id'], 'policy_revision' => (int)$policy['revision'], 'template_revision_id' => (int)$c['binding']['template_revision_id'], 'base_price' => Money::parse((string)$policy['base_price'])->decimal(),
            'source_budget_id' => $budget ? (int)$budget['id'] : null, 'available_before' => $available->decimal(), 'personal_charge' => $gap->decimal(), 'wallet_before' => null, 'wallet_after' => null,
            'storage_id' => (int)$c['storage']['id'], 'exposure_class' => $c['room']['details']['exposure_class']];
        if (!$gap->isZero()) {
            $person = $this->one('persone', ['user_id' => $user]);
            if (!$person || Money::parse((string)$person['credit'])->compare($gap) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'Личных кредитов не хватает для пополнения бюджета.');
            $terms['wallet_before'] = Money::parse((string)$person['credit'])->decimal(); $terms['wallet_after'] = Money::parse($terms['wallet_before'])->subtract($gap)->decimal();
            Money::parse((string)($budget['amount'] ?? '0'))->add($gap);
        }
        $revisions = ['node:' . $input['node_id'] => $c['room']['revision'], 'node:' . $recipient['id'] => $recipient['revision'], 'storage:' . $c['storage']['id'] => (int)$c['storage']['revision']];
        if ($budget) { $revisions['account:' . $budget['id']] = (int)$budget['revision']; $spend->allocation((int)$budget['id'], $total); }
        $rule = (new TreasuryLedger($this->db))->published($recipient['id']); $target = (new EconomyHierarchy($this->db))->accounts($recipient['id']);
        if (!isset($target['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна поселения недоступна.');
        Money::parse((string)$target['treasury']['amount'])->add($total);
        $terms += ['recipient_node_id' => $recipient['id'], 'recipient_name' => $recipient['name'], 'recipient_account_id' => (int)$target['treasury']['id'], 'recipient_policy_id' => $rule['history_id']];
        return $c + compact('terms', 'revisions');
    }
    private function prepared(int $user, array $input): array
    {
        try { return $this->prepare($user, $input); }
        catch (\OverflowException $e) { throw new GameError('EXPANSION_AMOUNT_LIMIT', 'Сумма операции превышает допустимый предел.', 422); }
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.equipment.expand', $input, function () use ($user, $input) { return $this->prepared($user, $input); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.equipment.expand', $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $bus) {
            $p = $this->prepared($user, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('EXPANSION_CHANGED', 'Условия изменились. Повторите расчёт.');
            $funding = null; $gap = Money::parse($terms['personal_charge']);
            if (!$gap->isZero()) $funding = (new BudgetFunding($this->db))->contribute($user, $payload['node_id'], $gap, 'Расширение мест оборудования', $operation);
            $accounts = (new EconomyHierarchy($this->db))->accounts($payload['node_id']); $spend = new BudgetSpending($this->db); $total = Money::parse($terms['total']);
            $hold = $spend->reserve((int)$accounts['budget']['id'], $total, 'Расширение мест оборудования', $operation);
            $transfer = $spend->pay($hold, $terms['recipient_node_id'], $total, $operation, 'equipment_expansion');
            foreach ($terms['unit_prices'] as $unit) {
                $slot = $this->one('world_slot', ['storage_id' => $terms['storage_id'], 'position' => $unit['ordinal'], 'status' => 'locked']);
                if (!$slot || $this->db->createCommand()->update('world_slot', ['status' => 'active'], ['storage_id' => $terms['storage_id'], 'position' => $unit['ordinal'], 'status' => 'locked'])->execute() !== 1) throw new \RuntimeException('Equipment place opening failed.');
                $this->entitle($terms['policy_id'], $terms['storage_id'], $unit['ordinal'], $unit['price'], $terms['policy_revision'], $transfer, $operation);
            }
            if ($this->db->createCommand()->update('craft_storage', ['capacity' => $terms['unlocked'] + $terms['quantity'], 'revision' => new Expression('[[revision]]+1')], ['id' => $terms['storage_id'], 'capacity' => $terms['unlocked'], 'revision' => $p['storage']['revision']])->execute() !== 1) throw new \RuntimeException('Equipment capacity write failed.');
            $ancestors = (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $payload['node_id']])->column($this->db);
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $ancestors])->execute();
            $result = ['changed_node_ids' => [$payload['node_id'], $terms['recipient_node_id']], 'changed_storage_ids' => [$terms['storage_id']]];
            (new WorldTree($this->db))->audit($user, 'world.equipment.expand', 'Покупка постоянных мест оборудования', $terms, $result + ['id' => $payload['node_id']], $operation);
            if ($funding) $bus->emit($operation, $user, 'economy.invested', ['node_id' => $payload['node_id'], 'transfer_id' => $funding['transfer_id'], 'amount' => $gap->decimal()]);
            $bus->emit($operation, $user, 'world.equipment.expanded', $result);
            return $result;
        });
    }
    private function entitle(int $policy, int $storage, int $ordinal, string $price, int $revision, ?int $transfer, string $operation): void
    {
        $this->db->createCommand()->insert('world_slot_entitlement', ['policy_id' => $policy, 'storage_id' => $storage, 'position' => $ordinal, 'price' => $price, 'policy_revision' => $revision, 'transfer_id' => $transfer, 'operation_id' => $operation, 'created_at' => time()])->execute();
    }
}
