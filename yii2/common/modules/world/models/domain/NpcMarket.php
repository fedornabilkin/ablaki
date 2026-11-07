<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\WorldTime;
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage, ProductionReservations, StorageAccessPolicy};
use common\modules\world\modules\economy\models\domain\FinanceReadSnapshot;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\db\{Connection, Query};

/** A city counter backed by the bot's real stock and finite personal wallet. */
final class NpcMarket
{
    public function __construct(private readonly Connection $db) {}

    private function context(int $city, int $user): array
    {
        EconomyRuntime::requireAccess($this->db, $user);
        $revision = EconomyRuntime::current($this->db);
        $npc = (new NpcInfrastructure($this->db))->policy();
        $market = $this->db->schema->getTableSchema('world_npc_market') ? (new Query())->from('world_npc_market')->where(['city_id' => $city])->one($this->db) : null;
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->record($city);
        if (!$market || !$npc || !isset($revision['raw_profile']['market']) || $node['node_type'] !== 'SETTLEMENT'
            || (int)$node['root_id'] !== (int)$npc['world_id'] || !NodeAvailability::activePath($this->db, $city)
            || (int)$market['bot_user_id'] !== (int)$npc['bot_user_id'] || (int)$node['owner_user_id'] !== (int)$npc['bot_user_id']) {
            throw new GameError('MARKET_UNAVAILABLE', 'Городской рынок сейчас недоступен.', 409);
        }
        return [$market, $revision, $revision['raw_profile']['market']];
    }

    private function catalogue(array $revision): array
    {
        $codes = EconomyRuntime::rawCodes($this->db);
        $rows = (new Query())->from('craft_item')->where(['active' => 1, 'kind' => 'material', 'storage_kind' => 'none', 'code' => $codes])->indexBy('id')->all($this->db);
        return array_filter($rows, static fn(array $item): bool => isset($revision['profile']['items'][$item['code']]['reference_price_cr'])
            && Money::parse($revision['profile']['items'][$item['code']]['reference_price_cr'])->compare(Money::parse('0')) > 0);
    }

    public function listing(int $city, int $user): array
    {
        return (new FinanceReadSnapshot($this->db))->run(function () use ($city, $user) {
            [$market, $revision, $policy] = $this->context($city, $user);
            $items = $this->catalogue($revision); $stock = $backpack = [];
            $reservations = new ProductionReservations($this->db);
            $pack = (new CanonicalInventory(new CraftStorage($this->db)))->backpack($user);
            $storages = [(int)$market['storage_id']]; if ($pack) $storages[] = (int)$pack['id'];
            foreach ((new Query())->from('craft_inventory')->where(['storage_id' => $storages, 'item_id' => array_keys($items)])->andWhere(['>', 'item_quantity', 0])->orderBy('id')->all($this->db) as $row) {
                if ((int)$row['storage_id'] !== (int)$market['storage_id'] && ((int)$row['slot'] > (int)$pack['capacity'] || (int)$row['slot'] < 1)) continue;
                $available = (int)$row['item_quantity'] - $reservations->quantity((int)$row['id']);
                if ($available < 1) continue;
                $item = $items[$row['item_id']];
                $dto = ['inventory_id' => (int)$row['id'], 'item_id' => (int)$item['id'], 'name' => $item['name'], 'icon' => $item['icon'], 'quantity' => $available,
                    'prices' => MarketPricing::unit($revision['profile']['items'][$item['code']]['reference_price_cr'], $policy)];
                if ((int)$row['storage_id'] === (int)$market['storage_id']) $stock[] = $dto; else $backpack[] = $dto;
            }
            $wallet = Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $market['bot_user_id']])->scalar($this->db));
            $reserve = Money::parse($policy['bot_reserve_cr']);
            return ['market' => ['city_id' => $city, 'profile_version' => $revision['version'], 'max_quantity' => $policy['max_quantity'],
                'next_delivery_at' => (int)$market['last_restock_at'] + $policy['refill_seconds'],
                'buyback_available_cr' => ($wallet->compare($reserve) > 0 ? $wallet->subtract($reserve) : Money::parse('0'))->decimal(),
                'fees' => array_intersect_key($policy, array_flip(['delivery_bps', 'city_bps', 'owner_bps', 'sell_burn_bps']))],
                'buy' => ['items' => $stock, 'limits' => $this->purchaseUsage($user, $policy)],
                'sell' => ['items' => $backpack, 'limits' => EconomyQuota::state($this->db, $user, 'sales_daily')]];
        });
    }

    private function purchaseUsage(int $user, array $policy): array
    {
        $window = WorldTime::dayWindow($this->db, time()); $spent = Money::parse('0');
        $rows = (new Query())->from('world_market_trade')->where(['user_id' => $user, 'direction' => 'buy'])->andWhere(['>=', 'created_at', $window['start']])->all($this->db);
        foreach ($rows as $row) $spent = $spent->add(Money::parse((string)$row['total_cr']));
        return ['remaining' => max(0, $policy['buy_daily_operations'] - count($rows)), 'spent_cr' => $spent->decimal(), 'maximum_cr' => $policy['buy_daily_cr'], 'resets_at' => $window['end']];
    }

    /** Caller uses Command::run: catalogue, all wallet owners and registry are already locked. */
    public function trade(int $user, array $input, string $operation): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Market trade requires a locked transaction.');
        [$market, $revision, $policy] = $this->context($input['city_id'], $user);
        $direction = $input['direction']; $quantity = $input['quantity'];
        if (!in_array($direction, ['buy', 'sell'], true) || !is_int($quantity) || $quantity < 1 || $quantity > $policy['max_quantity'] || $user === (int)$market['bot_user_id']) throw new GameError('INVALID_TRADE', 'Проверьте направление и количество сделки.', 422);
        $row = (new Query())->from('craft_inventory')->where(['id' => $input['inventory_id']])->one($this->db);
        $item = $row ? ($this->catalogue($revision)[$row['item_id']] ?? null) : null;
        if (!$item) throw new GameError('MARKET_STOCK_CHANGED', 'Этот ресурс больше недоступен.', 409);
        $prices = MarketPricing::unit($revision['profile']['items'][$item['code']]['reference_price_cr'], $policy);
        $unit = $prices[$direction . '_cr']; $total = Money::parse($unit)->multiply($quantity);
        if ($input['profile_version'] !== $revision['version'] || $input['unit_price_cr'] !== $unit) throw new GameError('MARKET_PRICE_CHANGED', 'Цены изменились. Обновите рынок и подтвердите сумму.', 409);
        $store = new CraftStorage($this->db); $store->operationId = $operation;
        $inventory = new CanonicalInventory($store); $access = new StorageAccessPolicy($this->db);
        $bot = (int)$market['bot_user_id'];
        if ($direction === 'buy') {
            if ((int)$row['storage_id'] !== (int)$market['storage_id'] || (int)$row['user_id'] !== $bot) throw new GameError('INVALID_STOCK', 'Выберите ячейку рынка.', 422);
            $usage = $this->purchaseUsage($user, $policy);
            if (!$usage['remaining'] || Money::parse($usage['spent_cr'])->add($total)->compare(Money::parse($policy['buy_daily_cr'])) > 0) throw new GameError('MARKET_QUOTA_EXHAUSTED', 'Дневной лимит покупок исчерпан.', 409);
            $target = $inventory->backpack($user);
            if (!$target) throw new GameError('BACKPACK_UNAVAILABLE', 'Сначала получите рюкзак в мире.', 409);
            $target = $access->storage($user, (int)$target['id'], true);
            $recipient = $user;
        } else {
            $inventory->inspectOrderDelivery($user, (int)$row['id'], (int)$item['id'], $quantity);
            $gross = Money::parse($prices['bid_cr'])->multiply($quantity);
            $wallet = Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $bot])->scalar($this->db));
            if ($wallet->subtract($gross)->compare(Money::parse($policy['bot_reserve_cr'])) < 0) throw new GameError('MARKET_FUNDS_LOW', 'Резерв выкупа исчерпан. Средства вернутся после покупок других игроков.', 409);
            EconomyQuota::consume($this->db, $user, 'sales_daily', 1, $operation, $gross->decimal());
            $target = $access->storage($bot, (int)$market['storage_id'], true); $recipient = $bot;
        }
        $plan = $inventory->planCraft([], $target, [], [], $item, $quantity);
        if (!$plan['output_fits']) throw new GameError('MARKET_STORAGE_FULL', $direction === 'buy' ? 'Освободите место в рюкзаке.' : 'Склад рынка заполнен.', 409);
        if ($direction === 'buy') $inventory->consumeHarvest($bot, (int)$row['id'], $quantity, 'harvest.sold');
        else $inventory->consumeMarket($user, (int)$row['id'], (int)$item['id'], $quantity);
        $inventory->applyCraft($recipient, $plan, $target, [], $item, 'market');
        $burn = (new MarketSettlement($this->db))->settle($market, $user, $direction, $prices, $quantity, $operation);
        $this->db->createCommand()->insert('world_market_trade', ['market_id' => $market['id'], 'user_id' => $user, 'direction' => $direction,
            'item_id' => $item['id'], 'quantity' => $quantity, 'unit_price_cr' => $unit, 'total_cr' => $total->decimal(), 'burned_cr' => $burn->decimal(),
            'operation_id' => $operation, 'profile_id' => $revision['id'], 'created_at' => time()])->execute();
        return ['trade' => ['direction' => $direction, 'quantity' => $quantity, 'total_cr' => $total->decimal(), 'burned_cr' => $burn->decimal()], 'changed_node_ids' => [(int)$market['city_id']]];
    }
}
