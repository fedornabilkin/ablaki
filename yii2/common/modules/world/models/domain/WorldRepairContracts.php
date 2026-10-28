<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** An optional, owner-accepted supplement to a purchase without a repair contract. */
class WorldRepairContracts
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    public function effective(array $purchase, array $terms): array
    {
        $original = $terms['config']['repair'] ?? null;
        $row = (new Query())->from('world_repair_contract')->where(['building_id' => $purchase['building_id']])->one($this->db);
        if (!$row) return ['id' => null, 'source' => $original ? 'purchase' : 'none', 'repair' => $original,
            'template_revision_id' => (int)$terms['template_revision_id'], 'accepted_at' => $original ? (int)$purchase['created_at'] : null];
        if ($original || (int)$row['purchase_id'] !== (int)$purchase['id'] || (int)$row['user_id'] !== (int)$purchase['user_id']) throw new GameError('REPAIR_CONTRACT_INVALID', 'Принадлежность договора ремонта требует сверки.');
        $saved = json_decode($row['terms_json'], true, 512, JSON_THROW_ON_ERROR);
        if ((int)$saved['recipient_node_id'] !== (int)$terms['recipient_node_id'] || (int)$saved['purchase_id'] !== (int)$purchase['id'] || (int)$saved['node_id'] !== (int)$purchase['building_id'] || (int)$saved['offer_id'] !== (int)$row['offer_id']) throw new GameError('REPAIR_CONTRACT_INVALID', 'Условия договора ремонта требуют сверки.');
        return ['id' => (int)$row['id'], 'source' => 'supplement', 'repair' => $saved['repair'], 'template_revision_id' => (int)$saved['template_revision_id'], 'accepted_at' => (int)$row['created_at']];
    }
    private function context(int $user, int $node): array
    {
        $c = (new WorldBuildingOperation($this->db, $this->flags))->context($user, $node, true);
        $purchase = (new Query())->from('world_premises_purchase')->where(['building_id' => $node, 'user_id' => $user])->one($this->db);
        $terms = $purchase ? json_decode($purchase['terms_json'], true, 512, JSON_THROW_ON_ERROR) : [];
        $effective = $purchase ? $this->effective($purchase, $terms) : null;
        $settlement = null;
        if (!$purchase) $c['reasons'][] = 'Договор подключается только к купленной постройке.';
        else {
            if ($effective['repair']) $c['reasons'][] = 'Договор ремонта уже действует. Замена условий не предусмотрена.';
            if ((int)$purchase['plot_id'] !== (int)$c['node']['parent_id'] || count($c['rooms']) !== 1 || (int)$c['rooms'][0]['id'] !== (int)$purchase['room_id']) $c['reasons'][] = 'Структура здания отличается от исходной покупки.';
            $room = (new Query())->from('world_room')->where(['node_id' => $purchase['room_id']])->one($this->db);
            if (!$room || (int)$room['area'] !== (int)$terms['config']['area']) $c['reasons'][] = 'Площадь комнаты отличается от исходной покупки.';
            foreach ($c['rooms'] as $ownedRoom) if ($ownedRoom['status'] !== 'active') { $c['reasons'][] = 'Комната постройки недоступна.'; break; }
            try {
                $settlement = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node((int)$terms['recipient_node_id']);
                if ($settlement['type'] !== 'SETTLEMENT' || $settlement['status'] !== 'active' || $settlement['root_id'] !== $c['node']['root_id']
                    || (new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $settlement['id']])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('REPAIR_RECIPIENT_UNAVAILABLE', 'Поселение-подрядчик недоступно.');
            } catch (GameError $error) { $c['reasons'][] = 'Поселение-подрядчик недоступно.'; $settlement = null; }
        }
        return $c + compact('purchase', 'terms', 'effective', 'settlement');
    }
    private function offers(array $c): Query
    {
        return (new Query())->select(['o.*', 'r.config_json'])->from(['o' => 'world_premises_offer'])
            ->innerJoin(['i' => 'world_repair_offer'], '[[i.offer_id]]=[[o.id]]')->innerJoin(['r' => 'world_template_revision'], '[[r.id]]=[[o.template_revision_id]]')
            ->where(['o.settlement_id' => $c['settlement']['id'], 'o.status' => 'published', 'r.status' => 'published', 'i.kind' => $c['terms']['config']['kind'], 'i.area' => $c['terms']['config']['area']]);
    }
    private function offerTerms(array $offer, array $c): array
    {
        $config = json_decode($offer['config_json'], true, 512, JSON_THROW_ON_ERROR);
        if (empty($config['repair_for_existing']) || empty($config['repair']) || $config['kind'] !== $c['terms']['config']['kind'] || $config['area'] !== $c['terms']['config']['area']) throw new GameError('REPAIR_OFFER_UNAVAILABLE', 'Предложение не подходит этой постройке.');
        return ['offer_id' => (int)$offer['id'], 'name' => $offer['name'], 'template_revision_id' => (int)$offer['template_revision_id'],
            'repair' => $config['repair'], 'kind' => $config['kind'], 'area' => $config['area']];
    }
    public function listing(int $user, int $node, int $page, string $search): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_FILTER', 'Некорректные параметры списка договоров.', 422);
        $c = $this->context($user, $node); $items = []; $total = 0;
        if (!$c['reasons']) {
            $query = $this->offers($c);
            if ($search !== '') $query->andWhere(['like', 'o.name', $search]);
            $total = (int)(clone $query)->count('*', $this->db);
            foreach ($query->orderBy(['o.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $offer) {
                $item = $this->offerTerms($offer, $c); $reasons = [];
                try { (new BuildingRepairSpec($this->db))->resolve($item['repair']); }
                catch (GameError $error) { $reasons[] = $error->getMessage(); }
                $items[] = $item + ['available' => !$reasons, 'reasons' => $reasons];
            }
        }
        return ['node_id' => $node, 'contract' => $c['effective'] && $c['effective']['repair'] ? $c['effective'] : null, 'items' => $items,
            'eligible' => !$c['reasons'], 'reasons' => $c['reasons'], 'writable' => $this->flags->capabilities()['world_write'],
            '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20], 'server_time' => time()];
    }
    private function prepare(int $user, array $input): array
    {
        $c = $this->context($user, $input['node_id']);
        if ($c['reasons']) throw new GameError('REPAIR_CONTRACT_UNAVAILABLE', implode(' ', $c['reasons']));
        $offer = $this->offers($c)->andWhere(['o.id' => $input['offer_id']])->one($this->db);
        if (!$offer) throw new GameError('REPAIR_OFFER_UNAVAILABLE', 'Предложение снято с продажи или не подходит зданию.');
        $selected = $this->offerTerms($offer, $c);
        $selected['repair'] = (new BuildingRepairSpec($this->db))->resolve($selected['repair']);
        $terms = $input + $selected + ['purchase_id' => (int)$c['purchase']['id'], 'building_name' => $c['node']['name'], 'price' => '0.0000',
            'recipient_node_id' => $c['settlement']['id'], 'recipient_name' => $c['settlement']['name']];
        $revisions = ['node:' . $input['node_id'] => $c['node']['revision'], 'node:' . $c['settlement']['id'] => $c['settlement']['revision']];
        foreach ($c['rooms'] as $room) $revisions['node:' . $room['id']] = (int)$room['revision'];
        return compact('terms', 'revisions');
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.building.repair-contract', $input, function () use ($user, $input) { return $this->prepare($user, $input); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.building.repair-contract', $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $bus) {
            $p = $this->prepare($user, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('REPAIR_CONTRACT_CHANGED', 'Условия договора изменились. Повторите расчёт.');
            $this->db->createCommand()->insert('world_repair_contract', ['building_id' => $payload['node_id'], 'purchase_id' => $terms['purchase_id'], 'offer_id' => $payload['offer_id'],
                'user_id' => $user, 'operation_id' => $operation, 'terms_json' => CanonicalJson::encode($terms), 'created_at' => time()])->execute();
            $contract = (int)$this->db->getLastInsertID();
            $ids = array_map('intval', (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $payload['node_id']])->column($this->db));
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $ids])->execute();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            $result = ['changed_node_ids' => $ids, 'changed_storage_ids' => []];
            (new WorldTree($this->db))->audit($user, 'world.building.repair-contract', 'Принятие договора ремонта владельцем', ['purchase_id' => $terms['purchase_id']], ['id' => $payload['node_id'], 'contract_id' => $contract, 'terms' => $terms], $operation);
            $bus->emit($operation, $user, 'world.building.repair-contract.accepted', $result); return $result;
        });
    }
}
