<?php
namespace common\modules\world\service;

use common\modules\economy\service\BudgetSpending;
use common\modules\economy\service\EconomyHierarchy;
use common\modules\economy\service\TreasuryLedger;
use common\modules\economy\service\WalletSchema;
use common\modules\economy\value\Money;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Purchase a ready protective building/room from a settlement, using the plot's budget. */
class WorldPremises
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }
    private function context(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read');
        $reader = new WorldQuery($this->db, new WorldAccessPolicy($user)); $node = $reader->node($id); $site = null;
        $node['details'] = (array)$node['details'];
        if ($node['type'] === 'PLOT') {
            if (!$node['permissions']['storage'] || $node['details']['plot_kind'] !== 'campsite' || empty($node['details']['allow_building'])) throw new GameError('PREMISES_SITE_REQUIRED', 'Выберите собственную стартовую площадку, на которой разрешены постройки.', 403);
            $site = $node; $node = $reader->node($node['parent_id']);
        }
        if ($node['type'] !== 'SETTLEMENT' || $node['status'] !== 'active' || $node['visibility'] !== 'public' || ($site && $site['status'] !== 'active')) throw new GameError('PREMISES_PLACE_UNAVAILABLE', 'Поселение или площадка недоступны.');
        $owner = (new Query())->select('owner_user_id')->from('world_node')->where(['id' => $node['id']])->scalar($this->db);
        $manager = $owner !== null ? (int)$owner === $user : $this->access->isAdmin();
        return ['settlement' => $node, 'site' => $site, 'manager' => $manager];
    }
    private function ready(): bool { return $this->flags->capabilities()['world_write'] && $this->flags->capabilities()['storage_v2'] && WalletSchema::ready($this->db); }
    private function area(array $site): array
    {
        $occupied = [];
        foreach ((new Query())->from('world_node')->where(['parent_id' => $site['id']])->andWhere(['<>', 'status', 'archived'])->all($this->db) as $child)
            foreach (WorldMapGeometry::cells($child['footprint_json'], (int)$child['position_x'], (int)$child['position_y']) as $cell) $occupied[$cell['x'] . ':' . $cell['y']] = true;
        $total = $site['map']['width'] * $site['map']['height']; $used = count($occupied);
        return ['total' => $total, 'used' => $used, 'available' => max(0, $total - $used), 'unaccounted_building' => false];
    }

    public function listing(int $user, int $id, int $page, string $search): array
    {
        $c = $this->context($user, $id);
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_PAGINATION', 'Некорректные параметры списка.', 422);
        $query = (new Query())->select(['o.*', 'r.config_json'])->from(['o' => 'world_premises_offer'])->innerJoin(['r' => 'world_template_revision'], '[[r.id]]=[[o.template_revision_id]]')
            ->where(['o.settlement_id' => $c['settlement']['id'], 'o.status' => 'published', 'r.status' => 'published']);
        if ($search !== '') $query->andWhere(['like', 'o.name', $search]);
        $total = (int)(clone $query)->count('*', $this->db); $items = []; $requirements = new RequirementEvaluator($this->db); $availableBudget = null;
        if ($c['site']) {
            $accounts = (new EconomyHierarchy($this->db))->accounts((int)$c['site']['id']);
            if (isset($accounts['budget'])) $availableBudget = (new BudgetSpending($this->db))->available((int)$accounts['budget']['id'])->decimal();
        }
        foreach ($query->orderBy(['o.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
            $config = json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $items[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'template_revision_id' => (int)$row['template_revision_id'], 'kind' => $config['kind'],
                'price' => $config['price'], 'area' => $config['area'], 'slots' => $config['slots'], 'lodging_places' => $config['lodging_places'] ?? 0, 'exposure_class' => $config['exposure_class'],
                'repair' => $config['repair'] ?? null, 'repair_for_existing' => $config['repair_for_existing'] ?? false, 'requirements' => $config['requirements'] ?? ['all' => []], 'requirements_status' => $requirements->evaluate($user, $config['requirements'] ?? []),
                'budget_available' => $availableBudget, 'can_afford' => $availableBudget === null ? null : Money::parse((string)$config['price'])->compare(Money::parse($availableBudget)) <= 0] + WorldEquipmentExpansion::terms($config) + ConstructionSpec::presentation($config);
        }
        return ['node_id' => $id, 'settlement_id' => $c['settlement']['id'], 'settlement_name' => $c['settlement']['name'], 'items' => $items,
            '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20],
            'can_publish' => !$c['site'] && $c['manager'] && $this->flags->capabilities()['world_write'], 'can_buy' => $c['site'] !== null && $this->ready(),
            'area' => $c['site'] ? $this->area($c['site']) : null, 'budget_available' => $availableBudget, 'server_time' => time()];
    }
    /** Used by the HTTP boundary and again by the domain; prices have no automatic default. */
    public function publication(array $body): array
    {
        $existingRepair = $body['repair_for_existing'] ?? false;
        if (!is_bool($existingRepair) || ($existingRepair && empty($body['repair']))) throw new GameError('INVALID_REPAIR_POLICY', 'Для предложения ремонта прежним зданиям задайте условия ремонта.', 422);
        if (!is_string($body['name'] ?? null) || trim($body['name']) === '' || mb_strlen($body['name'], 'UTF-8') > 120 || !in_array($body['kind'] ?? null, ['canopy', 'workroom', 'house', 'forge', 'workshop', 'warehouse'], true)) throw new GameError('INVALID_PREMISES', 'Укажите название и тип помещения.', 422);
        foreach (['area', 'slots'] as $key) if (!is_int($body[$key] ?? null) || $body[$key] < 1 || $body[$key] > 4) throw new GameError('INVALID_PREMISES', 'Площадь и количество мест должны быть от 1 до 4.', 422);
        if ($body['slots'] > $body['area']) throw new GameError('INVALID_PREMISES', 'Для каждого места оборудования нужна единица площади.', 422);
        if (!is_string($body['price'] ?? null)) throw new GameError('INVALID_AMOUNT', 'Укажите цену строкой.', 422);
        try { $price = Money::parse($body['price']); }
        catch (\Exception $e) { throw new GameError('INVALID_AMOUNT', 'Некорректная цена.', 422); }
        if ($price->isNegative() || $price->isZero()) throw new GameError('INVALID_AMOUNT', 'Цена должна быть положительной.', 422);
        return ['name' => trim($body['name']), 'kind' => $body['kind'], 'area' => $body['area'], 'slots' => $body['slots'], 'price' => $price->decimal(), 'lodging_places' => $body['kind'] === 'house' ? 1 : 0,
            'repair' => (new BuildingRepairSpec($this->db))->publication($body['repair'] ?? null), 'repair_for_existing' => $existingRepair,
            'requirements' => (new RequirementEvaluator($this->db))->validate($body['requirements'] ?? [])] + WorldEquipmentExpansion::terms($body) + (new ConstructionSpec($this->db))->publication($body);
    }
    private function offer(int $settlement, int $id): array
    {
        $row = (new Query())->select(['o.*', 'r.config_json'])->from(['o' => 'world_premises_offer'])->innerJoin(['r' => 'world_template_revision'], '[[r.id]]=[[o.template_revision_id]]')
            ->where(['o.id' => $id, 'o.settlement_id' => $settlement, 'o.status' => 'published', 'r.status' => 'published'])->one($this->db);
        if (!$row) throw new GameError('PREMISES_OFFER_UNAVAILABLE', 'Предложение больше недоступно.', 404);
        return $row;
    }
    private function prepare(int $user, array $input, string $action): array
    {
        if (array_key_exists('admin_reason', $input) && (!is_string($input['admin_reason']) || trim($input['admin_reason']) === '' || mb_strlen($input['admin_reason'], 'UTF-8') > 255)) throw new GameError('INVALID_REASON', 'Укажите причину изменения (до 255 символов).', 422);
        $c = $this->context($user, $input['node_id']); $place = $c['settlement'];
        $revisions = ['node:' . $place['id'] => $place['revision']]; $terms = $input; $result = $c;
        if ($action === 'publish') {
            if ($c['site'] || !$c['manager']) throw new GameError('PREMISES_MANAGEMENT_FORBIDDEN', 'Предложения публикует владелец поселения или администратор системного поселения.', 403);
            if (isset($input['replaces_offer_id'])) $result['replaced_offer'] = $this->offer($place['id'], $input['replaces_offer_id']);
            $config = $this->publication($input) + ['exposure_class' => $input['kind'] === 'canopy' ? 'covered' : 'indoor', 'delivery' => 'ready'];
            $config['materials'] = (new ConstructionSpec($this->db))->resolvedMaterials($config['materials']);
            if ($config['repair'] !== null) $config['repair'] = (new BuildingRepairSpec($this->db))->resolve($config['repair']);
            $terms['config'] = $config;
        } elseif ($action === 'withdraw') {
            if ($c['site'] || !$c['manager']) throw new GameError('PREMISES_MANAGEMENT_FORBIDDEN', 'Нет прав на снятие предложения.', 403);
            $offer = $this->offer($place['id'], $input['offer_id']);
            $terms += ['name' => $offer['name'], 'template_revision_id' => (int)$offer['template_revision_id']];
            $result['offer'] = $offer;
        } elseif ($action === 'buy') {
            $this->flags->requireFlag('storage_v2'); WalletSchema::requireReady($this->db); $site = $c['site'];
            if (!$site) throw new GameError('PREMISES_SITE_REQUIRED', 'Покупка выполняется из собственной площадки.', 403);
            // Bind delivery to its direct plot explicitly; finance still uses settlement scope.
            $terms['site_node_id'] = (int)$site['id'];
            $offer = $this->offer($place['id'], $input['offer_id']); $config = json_decode($offer['config_json'], true, 512, JSON_THROW_ON_ERROR);
            (new RequirementEvaluator($this->db))->requireSatisfied($user, $config['requirements'] ?? []);
            if ($config['kind'] === 'house' && !(new Query())->from('world_membership')->where(['user_id' => $user, 'starter_site_id' => $site['id'], 'world_id' => $site['root_id']])->exists($this->db)) throw new GameError('HOUSING_SITE_REQUIRED', 'Дом с ночлегом можно купить на своей стартовой стоянке.');
            $area = $this->area($site);
            if ($area['available'] < 1) throw new GameError('PREMISES_AREA_REQUIRED', 'На площадке недостаточно свободной площади.');
            $accounts = (new EconomyHierarchy($this->db))->accounts($site['id']); $budget = $accounts['budget'] ?? null;
            if (!$budget) throw new GameError('INSUFFICIENT_BUDGET', 'Сначала пополните бюджет площадки.');
            $spend = new BudgetSpending($this->db); $price = Money::parse($config['price']);
            if ($spend->available((int)$budget['id'])->compare($price) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Свободных средств бюджета площадки недостаточно. Личные кредиты автоматически не списываются.');
            $spend->allocation((int)$budget['id'], $price);
            $policy = (new TreasuryLedger($this->db))->published($place['id']);
            $recipient = (new EconomyHierarchy($this->db))->accounts($place['id']);
            if (!isset($recipient['treasury'])) throw new GameError('TREASURY_UNAVAILABLE', 'Казна поселения недоступна.');
            Money::parse((string)$recipient['treasury']['amount'])->add($price);
            $revisions['node:' . $site['id']] = $site['revision']; $revisions['account:' . $budget['id']] = (int)$budget['revision'];
            $terms += ['config' => $config, 'template_revision_id' => (int)$offer['template_revision_id'], 'source_budget_id' => (int)$budget['id'],
                'recipient_node_id' => $place['id'], 'recipient_name' => $place['name'], 'recipient_account_id' => (int)$recipient['treasury']['id'],
                'recipient_policy_id' => $policy['history_id'], 'available_area' => $area['available'], 'personal_charge' => '0.0000'];
            $result += ['budget' => $budget, 'offer' => $offer];
            if (($config['delivery'] ?? 'ready') === 'construction') {
                $materials = (new ConstructionSpec($this->db))->materials($user, $config);
                $revisions += $materials['revisions']; $terms['material_plan'] = $materials['plan'];
                $terms['cancellation'] = 'full_refund_before_completion';
            }
        } else throw new \InvalidArgumentException('Unknown premises action.');
        return $result + compact('terms', 'revisions');
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.premises.' . $action, $input, function () use ($user, $input, $action) {
            try { return $this->prepare($user, $input, $action); }
            catch (\OverflowException $e) { throw new GameError('PREMISES_AMOUNT_LIMIT', 'Сумма покупки или остаток казны превышают допустимый предел.', 422); }
        });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.premises.' . $action, $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $action, $bus) {
            try { $p = $this->prepare($user, $payload, $action); }
            catch (\OverflowException $e) { throw new GameError('PREMISES_AMOUNT_LIMIT', 'Сумма покупки или остаток казны превышают допустимый предел.', 422); }
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('PREMISES_CHANGED', 'Условия покупки изменились. Повторите расчёт.');
            $changed = [$payload['node_id'], $p['settlement']['id']]; $storages = []; $created = [];
            if ($action === 'publish') {
                if (isset($p['replaced_offer'])) {
                    if ($this->db->createCommand()->update('world_premises_offer', ['status' => 'withdrawn'], ['id' => $p['replaced_offer']['id'], 'status' => 'published'])->execute() !== 1) throw new GameError('PREMISES_CHANGED', 'Предложение уже изменено.');
                }
                $this->db->createCommand()->insert('world_template', ['code' => 'premises-' . $operation, 'kind' => 'BUILDING'])->execute(); $template = (int)$this->db->getLastInsertID();
                $this->db->createCommand()->insert('world_template_revision', ['template_id' => $template, 'version' => 1, 'status' => 'published', 'config_json' => CanonicalJson::encode($terms['config']), 'author_user_id' => $user, 'published_at' => time()])->execute();
                $this->db->createCommand()->insert('world_premises_offer', ['settlement_id' => $payload['node_id'], 'template_revision_id' => (int)$this->db->getLastInsertID(), 'name' => $payload['name'], 'operation_id' => $operation, 'created_at' => time()])->execute();
                $published = $this->offer($payload['node_id'], (int)$this->db->getLastInsertID());
                $created['offer_id'] = (int)$published['id'];
                if (!empty($terms['config']['repair_for_existing'])) $this->db->createCommand()->insert('world_repair_offer', ['offer_id' => (int)$published['id'], 'kind' => $terms['config']['kind'], 'area' => $terms['config']['area']])->execute();
                (new WorldTree($this->db))->audit($user, 'world.premises.publish', $payload['admin_reason'] ?? 'Публикация предложения готовой постройки', isset($p['replaced_offer']) ? ['offer' => $p['replaced_offer']] : [],
                    ['id' => $p['settlement']['id'], 'offer' => $published], $operation);
            } elseif ($action === 'withdraw') {
                if ($this->db->createCommand()->update('world_premises_offer', ['status' => 'withdrawn'], ['id' => $payload['offer_id'], 'status' => 'published'])->execute() !== 1) throw new \RuntimeException('Offer withdrawal failed.');
                $before = ['id' => $p['settlement']['id'], 'offer' => $p['offer']]; $after = $before;
                $after['offer']['status'] = 'withdrawn';
                (new WorldTree($this->db))->audit($user, 'world.premises.withdraw', $payload['admin_reason'] ?? 'Снятие предложения готовой постройки', $before, $after, $operation);
            } else {
                $config = $terms['config']; $price = Money::parse($config['price']); $spend = new BudgetSpending($this->db);
                $hold = $spend->reserve($terms['source_budget_id'], $price, 'Покупка помещения: ' . $config['name'], $operation);
                if (($config['delivery'] ?? 'ready') === 'construction') {
                    $delivery = (new WorldConstruction($this->db, $this->flags))->start($user, $terms, $hold, $operation);
                } else {
                    $transfer = $spend->pay($hold, $terms['recipient_node_id'], $price, $operation, 'premises_purchase');
                    $delivery = (new PremisesDelivery($this->db, $this->flags))->deliver($user, $terms, $transfer, $operation);
                    (new WorldTree($this->db))->audit($user, 'world.premises.buy', 'Покупка готового помещения из бюджета площадки', [], (new WorldTree($this->db))->get($delivery['building_id']), $operation);
                }
                $changed = $delivery['changed_node_ids']; $storages = $delivery['changed_storage_ids'];
                $created = array_diff_key($delivery, array_flip(['changed_node_ids', 'changed_storage_ids']));
            }
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $p['settlement']['id']])->execute();
            $result = ['changed_node_ids' => array_values(array_unique($changed)), 'changed_storage_ids' => $storages] + $created;
            $bus->emit($operation, $user, 'world.premises.' . $action, $result);
            return $result;
        });
    }
}
