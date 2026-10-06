<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\WorldStorage;
use common\modules\world\modules\economy\models\domain\NodeEconomy;
use common\modules\world\modules\economy\models\domain\StarterOrders;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Read-only entry point for the player's starter campsite and its existing commands. */
class WorldCampsite
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }

    private function site(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read');
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
        $details = (array)$node['details'];
        if ($node['type'] !== 'PLOT' || $node['status'] !== 'active' || !$node['permissions']['storage'] || ($details['plot_kind'] ?? null) !== 'campsite'
            || !(new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $node['root_id'], 'starter_site_id' => $id])->exists($this->db)) {
            throw new GameError('CAMPSITE_OWNER_REQUIRED', 'Откройте свою действующую стартовую стоянку.', 403);
        }
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')
            ->where(['c.descendant_id' => $id])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) {
            throw new GameError('CAMPSITE_UNAVAILABLE', 'Стоянка временно недоступна.');
        }
        return $node;
    }

    private function action(string $code, bool $allowed, ?string $preview, string $execute, string $reason = '', array $payload = []): array
    {
        $result = ['code' => $code, 'allowed' => $allowed, 'reasons' => $allowed || $reason === '' ? [] : [['code' => 'ACTION_UNAVAILABLE', 'message' => $reason]],
            'preview' => $preview, 'execute' => $execute];
        if ($payload) $result['payload'] = $payload;
        return $result;
    }

    private function snapshot(int $user, int $id): array
    {
        $site = $this->site($user, $id); $flags = $this->flags->capabilities();
        $shelter = (new WorldShelter($this->db, $this->flags))->state($user, $id);
        $nights = (new WorldNights($this->db, $this->flags))->state($user, $id, 1, '');
        unset($nights['history']);
        $economy = (new NodeEconomy($this->db, $this->flags))->view($user, $id);
        unset($economy['entries']);
        $premises = (new WorldPremises($this->db, $this->flags, new WorldAccessPolicy($user)))->listing($user, $id, 1, '');
        $garden = (new WorldGarden($this->db, $this->flags, new WorldAccessPolicy($user)))->state($user, $id);
        $orders = (new StarterOrders($this->db, $this->flags, new WorldAccessPolicy($user)))->listing($user, (int)$site['parent_id'], 1, '', 'open');
        $storages = (new WorldStorage($this->db, $this->flags))->list($user, $id)['items'];
        $placement = null;
        foreach ($storages as $storage) if ($storage['kind'] === 'placement' && $storage['node_id'] === $id) {
            $placement = (new WorldStorage($this->db, $this->flags))->view($user, $storage['id']); break;
        }
        $hasDebt = (new Query())->from(['o' => 'economy_obligation'])
            ->innerJoin(['a' => 'economy_account'], '[[a.id]]=[[o.budget_account_id]]')
            ->innerJoin(['subject' => 'economy_subject'], '[[subject.id]]=[[a.subject_id]]')
            ->where(['subject.node_id' => $id])->andWhere(['<>', 'o.status', 'paid'])->exists($this->db);
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        $supplies = ['starter_available' => !(new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'starter'])->exists($this->db),
            'gather_available' => !(new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'gather'])->andWhere(['>=', 'created_at', $today])->exists($this->db),
            'gather_available_at' => $today + 86400];
        $credit = (new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($this->db);
        $walletCredit = $credit === false ? null : Money::parse((string)$credit)->decimal();
        return compact('site', 'flags', 'shelter', 'nights', 'economy', 'premises', 'garden', 'orders', 'storages', 'placement', 'hasDebt', 'supplies', 'walletCredit');
    }

    private function commands(int $id, array $s): array
    {
        $write = $s['flags']['world_write'] && $s['flags']['storage_v2'];
        $base = '/v1/world/nodes/' . $id;
        $settlementBase = '/v1/world/nodes/' . $s['site']['parent_id'];
        $shelter = $s['shelter']; $here = $shelter['deployment'] && $shelter['deployment']['plot_id'] === $id;
        $assigned = $here && $shelter['lodging'] !== null;
        $commands = [['code' => 'open', 'allowed' => true, 'reasons' => []]];
        foreach (['starter', 'gather'] as $operation) {
            $available = $s['supplies'][$operation === 'starter' ? 'starter_available' : 'gather_available'];
            $commands[] = $this->action('supplies.' . $operation, $write && $available,
                $base . '/supplies-' . $operation . '-preview', $base . '/supplies-' . $operation,
                $write ? 'Материалы уже получены в этом периоде.' : 'Запись в мире временно недоступна.');
        }
        foreach (['claim', 'deploy', 'fold', 'lodge', 'leave', 'repair'] as $operation) {
            $available = false;
            if ($operation === 'claim') $available = !$shelter['claimed'];
            if ($operation === 'deploy') $available = $shelter['claimed'] && !$shelter['deployment'] && (int)$shelter['durability'] > 0;
            if ($operation === 'fold') $available = $here;
            if ($operation === 'lodge') $available = $here && !$shelter['current_assignment'] && (int)$shelter['durability'] > 0;
            if ($operation === 'leave') $available = $assigned;
            if ($operation === 'repair') $available = $shelter['claimed'] && $shelter['instance_id'] !== null && $shelter['durability'] < $shelter['max_durability'];
            $commands[] = $this->action('shelter.' . $operation, $write && $available,
                $base . '/shelter-' . $operation . '-preview', $base . '/shelter-' . $operation,
                $write ? 'Состояние шалаша не допускает это действие.' : 'Запись в мире временно недоступна.',
                ['direct_deploy' => false, 'end_lodging' => false]);
        }
        $commands[] = $this->action('storage.transfer', $write && $s['placement'] !== null,
            '/v1/world/storage/transfer-preview', '/v1/world/storage/transfer', 'Нет доступных мест размещения или запись недоступна.');
        $commands[] = $this->action('storage.chest-repair', $write,
            '/v1/world/storage/chest-repair-preview', '/v1/world/storage/chest-repair', 'Запись в мире временно недоступна.');
        $commands[] = $this->action('workspace.craft', $write,
            '/v1/world/workspace/preview', '/v1/world/workspace/craft', 'Запись в мире временно недоступна.');
        $commands[] = $this->action('economy.invest', $s['economy']['can_invest'], $base . '/invest-preview', $base . '/invest', 'Пополнение бюджета недоступно.');
        $commands[] = $this->action('economy.grant', $write && $s['economy']['can_grant'],
            $base . '/budget-grant-preview', $base . '/budget-grant', 'Для перевода нужен доступный остаток бюджета.',
            $s['garden']['garden'] ? ['destination_node_id' => $s['garden']['garden']['node_id']] : []);
        $commands[] = $this->action('economy.collect', $s['economy']['collect_available'], $base . '/collect-preview', $base . '/collect', 'Сейчас нечего собирать или казна ожидает расчёта потерь.');
        $commands[] = $this->action('economy.pay', $s['economy']['can_pay'] && $s['hasDebt'], $base . '/pay-preview', $base . '/pay', 'Нет обязательства к оплате.');
        $commands[] = $this->action('orders.deliver', $s['orders']['can_deliver'] && (int)$s['orders']['_meta']['totalCount'] > 0,
            $settlementBase . '/order-deliver-preview', $settlementBase . '/order-deliver', 'В поселении нет доступного оплаченного заказа.');
        $commands[] = $this->action('premises.buy', $s['premises']['can_buy'] && (int)$s['premises']['area']['available'] > 0 && (int)$s['premises']['_meta']['totalCount'] > 0,
            $base . '/premises-buy-preview', $base . '/premises-buy', 'Нет свободной площади или опубликованного предложения.');
        $commands[] = $this->action('garden.buy', $s['garden']['can_buy'], $base . '/garden-buy-preview', $base . '/garden-buy', 'Огород уже приобретён или нет опубликованного предложения.', ['top_up' => false]);
        $garden = $s['garden']['garden'];
        if ($garden) {
            $gardenBase = '/v1/world/nodes/' . $garden['node_id'];
            $commands[] = $this->action('garden.expand', $write && $s['economy']['wallet_ready'] && $garden['unlocked'] < $garden['limit'],
                $gardenBase . '/garden-expand-preview', $gardenBase . '/garden-expand', 'Все грядки уже открыты или запись временно недоступна.', ['top_up' => false]);
            $commands[] = $this->action('garden.sell', $write && $s['orders']['can_deliver'],
                $settlementBase . '/order-deliver-preview', $settlementBase . '/order-deliver', 'Сдача урожая пока недоступна.',
                ['income_node_id' => $garden['beds'][0]['node_id']]);
        }
        return $commands;
    }

    public function actions(int $user, int $id): array
    {
        $state = $this->snapshot($user, $id);
        return ['node_id' => $id, 'items' => $this->commands($id, $state), 'server_time' => time()];
    }

    public function state(int $user, int $id): array
    {
        $s = $this->snapshot($user, $id);
        $reader = new WorldQuery($this->db, new WorldAccessPolicy($user));
        $children = $reader->children($id, ['per-page' => 100]);
        $buildings = [];
        foreach ($children['items'] as $child) if ($child['type'] === 'BUILDING' && $child['permissions']['storage']) {
            $buildingBase = '/v1/world/nodes/' . $child['id']; $childDetails = (array)$child['details'];
            if (isset($childDetails['shelter_instance_id'])) {
                $buildings[] = ['node_id' => $child['id'], 'kind' => 'shelter', 'shelter' => '/v1/world/nodes/' . $id . '/shelter'];
                continue;
            }
            $rooms = [];
            foreach ($reader->children((int)$child['id'], ['per-page' => 100])['items'] as $room) if ($room['type'] === 'ROOM' && $room['permissions']['storage']) {
                $roomBase = '/v1/world/nodes/' . $room['id'];
                $roomLinks = ['node_id' => $room['id'], 'name' => $room['name'], 'storages' => '/v1/world/storages?node_id=' . $room['id'],
                    'workspace' => '/v1/world/workspace?node_id=' . $room['id'], 'equipment_expansion' => $roomBase . '/equipment-expansion',
                ];
                if ((new Query())->from('world_housing_place')->where(['room_id' => $room['id']])->exists($this->db)) $roomLinks['housing'] = $roomBase . '/housing';
                $rooms[] = $roomLinks;
            }
            $buildings[] = ['node_id' => $child['id'], 'kind' => 'permanent', 'rooms_url' => $buildingBase . '/children', 'rooms' => $rooms,
                'construction' => $buildingBase . '/construction',
                'operation' => $buildingBase . '/building-operation', 'repair' => $buildingBase . '/building-repair',
                'demolition' => $buildingBase . '/demolition'];
        }
        $base = '/v1/world/nodes/' . $id;
        $links = ['shelter' => $base . '/shelter', 'nights' => $base . '/nights', 'economy' => $base . '/economy',
            'obligations' => $base . '/obligations', 'treasury_receipts' => $base . '/treasury-receipts',
            'premises' => $base . '/premises', 'garden' => $base . '/garden', 'construction' => $base . '/construction',
            'demolitions' => $base . '/demolitions', 'orders' => '/v1/world/nodes/' . $s['site']['parent_id'] . '/orders',
            'storages' => '/v1/world/storages?node_id=' . $id, 'workspace' => '/v1/world/workspace?node_id=' . $id,
            'budget_grant_preview' => $base . '/budget-grant-preview', 'budget_grant' => $base . '/budget-grant'];
        $beds = [];
        foreach ($s['garden']['garden']['beds'] ?? [] as $bed) {
            $bedBase = '/v1/world/beds/' . $bed['node_id'];
            $beds[] = $bed + ($bed['unlocked'] ? ['cultivation' => $bedBase . '/cultivation', 'preview_pattern' => $bedBase . '/{action}-preview',
                'execute_pattern' => $bedBase . '/{action}'] : []);
        }
        return ['node' => $s['site'], 'shelter' => $s['shelter'], 'nights' => $s['nights'], 'economy' => $s['economy'], 'wallet_credit' => $s['walletCredit'],
            'links' => $links, 'premises' => $s['premises'], 'garden' => $s['garden'],
            'beds' => $beds, 'orders' => $s['orders'], 'supplies' => $s['supplies'], 'storages' => $s['storages'], 'placement' => $s['placement'], 'children' => $children, 'buildings' => $buildings,
            'actions' => $this->commands($id, $s), 'server_time' => time()];
    }
}
