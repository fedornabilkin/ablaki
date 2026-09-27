<?php
namespace common\modules\world\service;

use common\modules\craft\service\CanonicalInventory;
use common\modules\craft\service\CraftInventory;
use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\EquipmentExposure;
use common\modules\craft\service\StorageAccessPolicy;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** One account grant; the deployed building is a projection of the same inventory unit. */
class WorldShelter
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    private function context(int $user, int $node): array
    {
        $this->flags->requireFlag('world_read');
        $site = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node);
        $site['details'] = (array)$site['details'];
        if ($site['type'] !== 'PLOT' || !$site['permissions']['storage'] || $site['status'] !== 'active' || ($site['details']['plot_kind'] ?? '') !== 'campsite'
            || !$this->one('world_membership', ['user_id' => $user, 'starter_site_id' => $node])) throw new GameError('SHELTER_SITE_REQUIRED', 'Откройте свою стартовую стоянку.', 403);
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $node])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('SHELTER_SITE_UNAVAILABLE', 'Стоянка временно недоступна.');
        return $site;
    }
    private function asset(int $user): array
    {
        $grant = $this->one('world_starter_grant', ['user_id' => $user, 'grant_code' => ShelterCatalog::CODE]);
        $unit = $grant ? $this->one('craft_equipment_instance', ['id' => $grant['instance_id'], 'status' => 'active']) : null;
        $row = $unit ? $this->one('craft_inventory', ['id' => $unit['inventory_id'], 'user_id' => $user, 'item_quantity' => 1]) : null;
        $deployment = $unit ? $this->one('world_shelter_deployment', ['active_instance_id' => $unit['id']]) : null;
        $lodging = $deployment ? $this->one('world_lodging_interval', ['active_deployment_id' => $deployment['id']]) : null;
        $protection = $deployment ? (new ShelterProtection($this->db))->current((int)$deployment['id']) : null;
        return compact('grant', 'unit', 'row', 'deployment', 'lodging', 'protection');
    }
    public function state(int $user, int $node): array
    {
        $site = $this->context($user, $node); $a = $this->asset($user); $unit = $a['unit'] ? (new EquipmentExposure($this->db))->projected($a['unit']) : null;
        $deployed = $a['deployment']; $here = $deployed && (int)$deployed['plot_id'] === $node;
        $actor = $this->one('game_actor', ['user_id' => $user, 'kind' => 'player']);
        return ['node_id' => $node, 'claimed' => (bool)$a['grant'], 'claimed_at' => $a['grant'] ? (int)$a['grant']['claimed_at'] : null,
            'instance_id' => $unit ? (int)$unit['id'] : null, 'inventory_id' => $a['row'] ? (int)$a['row']['id'] : null,
            'durability' => $unit ? (int)$unit['durability'] : null, 'max_durability' => $unit ? (int)$unit['max_durability'] : null,
            'deployment' => $deployed ? ['id' => (int)$deployed['id'], 'node_id' => (int)$deployed['node_id'], 'plot_id' => (int)$deployed['plot_id'], 'started_at' => (int)$deployed['started_at'], 'protected_until' => (int)$a['protection']['protected_until']] : null,
            'lodging' => $a['lodging'] ? ['assigned_at' => (int)$a['lodging']['started_at'], 'protects_now' => $here && (int)$unit['durability'] > 0 && time() < (int)$a['protection']['protected_until']] : null,
            'current_assignment' => $actor ? (new LodgingAssignments($this->db))->current((int)$actor['id']) : null,
            'writable' => $this->flags->capabilities()['world_write'] && $this->flags->capabilities()['storage_v2'], 'night_resolution_enabled' => (new WorldNights($this->db, $this->flags))->policy($site['root_id']) !== null, 'server_time' => time()];
    }
    private function backpackSlot(int $user, CanonicalInventory $inventory, CraftStorage $store): array
    {
        $bag = $inventory->backpack($user); $occupied = array_flip(array_map('intval', array_column((new CraftInventory($store))->layout($user), 'slot')));
        $capacity = (new CraftInventory($store))->capacity($user)['active_slots'];
        for ($position = 1; $position <= $capacity; $position++) if (!isset($occupied[$position])) return [$bag, $position];
        throw new GameError('SHELTER_BACKPACK_FULL', 'Освободите ячейку рюкзака. При получении можно сразу установить шалаш на стоянке.');
    }
    private function prepare(int $user, array $input, string $action): array
    {
        $this->flags->requireFlag('storage_v2'); $site = $this->context($user, $input['node_id']);
        if (!in_array($action, ['claim', 'deploy', 'fold', 'lodge', 'leave', 'repair'], true) || !is_bool($input['direct_deploy'] ?? null) || !is_bool($input['end_lodging'] ?? null)
            || ($action !== 'claim' && $input['direct_deploy']) || ($action !== 'fold' && $input['end_lodging'])) throw new GameError('INVALID_SHELTER_ACTION', 'Некорректное действие с шалашом.', 422);
        $a = $this->asset($user); $item = $this->one('craft_item', ['code' => ShelterCatalog::CODE, 'active' => 1]);
        if (!$item || $item['kind'] !== 'equipment' || (int)$item['stack_size'] !== 1 || $item['storage_kind'] !== 'none') throw new GameError('SHELTER_NOT_READY', 'Шалаш ещё не подготовлен.', 503);
        $store = new CraftStorage($this->db); $inventory = new CanonicalInventory($store);
        $terms = $input + ['item_id' => (int)$item['id'], 'price' => '0.0000', 'instance_id' => $a['unit'] ? (int)$a['unit']['id'] : null,
            'deployment_id' => $a['deployment'] ? (int)$a['deployment']['id'] : null, 'lodging_id' => $a['lodging'] ? (int)$a['lodging']['id'] : null,
            'protection_id' => $a['protection'] ? (int)$a['protection']['id'] : null, 'backpack_position' => null];
        $revisions = ['node:' . $site['id'] => $site['revision'], 'catalog' => (int)$this->one('craft_meta', ['id' => 1])['revision']];
        if ($action === 'claim') {
            if ($a['grant']) throw new GameError('SHELTER_ALREADY_CLAIMED', 'Разовый шалаш уже получен. Смена мира не восстанавливает право.');
        } else {
            if (!$a['unit'] || !$a['row'] || (int)$a['row']['item_id'] !== (int)$item['id']) throw new GameError('SHELTER_UNAVAILABLE', 'Ваш экземпляр шалаша недоступен.');
            $revisions['instance:' . $a['unit']['id']] = (int)$a['unit']['revision'];
            $revisions['inventory:' . $a['row']['id']] = (int)$a['row']['revision'];
        }
        if ($action === 'deploy' && $a['deployment']) throw new GameError('SHELTER_ALREADY_DEPLOYED', 'Сначала сложите установленный шалаш.');
        if ($action === 'deploy' || ($action === 'repair' && !$a['deployment'])) {
            $source = (new StorageAccessPolicy($this->db))->storage($user, (int)$a['row']['storage_id']);
            if ((int)$a['row']['slot'] < 1 || (int)$a['row']['slot'] > (int)$source['capacity']) throw new GameError('SHELTER_SLOT_INACTIVE', 'Перенесите шалаш в доступную ячейку.');
            $revisions['storage:' . $source['id']] = (int)$source['revision'];
        }
        if (in_array($action, ['fold', 'lodge', 'leave'], true) || ($action === 'repair' && $a['deployment'])) {
            if (!$a['deployment'] || (int)$a['deployment']['plot_id'] !== $site['id']) throw new GameError('SHELTER_NOT_HERE', 'Откройте стоянку, на которой установлен шалаш.');
            $building = $this->one('world_node', ['id' => $a['deployment']['node_id'], 'status' => 'active', 'owner_user_id' => $user]);
            $storage = $this->one('craft_storage', ['id' => $a['row']['storage_id'], 'node_id' => $a['deployment']['node_id'], 'kind' => 'shelter', 'status' => 'active', 'owner_user_id' => $user]);
            if (!$building || !$storage) throw new GameError('SHELTER_UNAVAILABLE', 'Размещение шалаша требует сверки.');
            $revisions['node:' . $building['id']] = (int)$building['revision']; $revisions['storage:' . $storage['id']] = (int)$storage['revision'];
        }
        if (in_array($action, ['deploy', 'lodge'], true) && (int)(new EquipmentExposure($this->db))->projected($a['unit'])['durability'] < 1) throw new GameError('SHELTER_BROKEN', 'Изношенный шалаш не защищает от непогоды.');
        if ($action === 'lodge') {
            $actor = $this->one('game_actor', ['user_id' => $user, 'kind' => 'player']);
            if (!$actor || (new LodgingAssignments($this->db))->current((int)$actor['id']) || $a['lodging']) throw new GameError('LODGING_ALREADY_ASSIGNED', 'Сначала отмените текущее назначение ночлега в доме или шалаше.');
            $terms['actor_id'] = (int)$actor['id'];
        }
        if ($action === 'leave' && !$a['lodging']) throw new GameError('LODGING_NOT_ASSIGNED', 'Ночлег уже отменён.');
        if ($action === 'fold' && $a['lodging'] && !$input['end_lodging']) throw new GameError('LODGING_CONFIRM_REQUIRED', 'Подтвердите прекращение ночлега перед складыванием.');
        if ($action === 'fold' || ($action === 'claim' && !$input['direct_deploy'])) {
            list($bag, $position) = $this->backpackSlot($user, $inventory, $store); $terms['backpack_position'] = $position;
            if ($bag) $revisions['storage:' . $bag['id']] = (int)$bag['revision'];
        }
        $repairAt = time();
        if ($action === 'repair') {
            $terms['repair'] = (new ShelterRepair($this->db))->quote($user, $a['unit'], $store, $repairAt);
            $bag = $inventory->backpack($user);
            if ($bag) $revisions['storage:' . $bag['id']] = (int)$bag['revision'];
        }
        return ['terms' => $terms, 'revisions' => $revisions, 'asset' => $a, 'item' => $item, 'repair_at' => $repairAt];
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.shelter.' . $action, $input, function () use ($user, $input, $action) { return $this->prepare($user, $input, $action); });
    }
    private function closeLodging(int $id, int $now): void
    {
        $this->db->createCommand()->update('world_lodging_interval', ['ended_at' => $now, 'active_actor_id' => null, 'active_deployment_id' => null], ['id' => $id, 'ended_at' => null])->execute();
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.shelter.' . $action, $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $action, $bus) {
            $p = $this->prepare($user, $payload, $action);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('SHELTER_CHANGED', 'Условия изменились. Повторите расчёт.');
            $a = $p['asset']; $store = new CraftStorage($this->db); $store->operationId = $operation; $inventory = new CanonicalInventory($store);
            $now = time(); $changed = [$payload['node_id']]; $storages = [];
            if ($action === 'claim' || $action === 'deploy' || $action === 'fold') {
                $deploy = $action === 'deploy' || ($action === 'claim' && $payload['direct_deploy']);
                if ($deploy) {
                    $code = 'shelter-' . $operation;
                    $building = (new WorldTree($this->db))->create(['code' => $code, 'slug' => $code, 'node_type' => 'BUILDING', 'name' => 'Походный шалаш', 'parent_id' => $payload['node_id'], 'owner_user_id' => $user, 'visibility' => 'private'], ['operational_status' => 'active']);
                    $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'shelter:node:' . $building['id'], 'kind' => 'shelter', 'owner_user_id' => $user, 'node_id' => $building['id'], 'capacity' => 1])->execute();
                    $target = $this->one('craft_storage', ['id' => (int)$this->db->getLastInsertID()]); $position = 1;
                    $changed[] = (int)$building['id'];
                } else {
                    $inventory->synchronize($user); $target = $inventory->backpack($user); $position = $terms['backpack_position'];
                }
                $moved = $inventory->shelter($user, $p['item'], $target, $position, $a['row']);
                $storages[] = (int)$target['id']; if ($a['row']) $storages[] = (int)$a['row']['storage_id'];
                if ($action === 'claim') $this->db->createCommand()->insert('world_starter_grant', ['user_id' => $user, 'grant_code' => ShelterCatalog::CODE, 'instance_id' => $moved['instance_id'], 'operation_id' => $operation, 'claimed_at' => $now])->execute();
                if ($deploy) {
                    $unit = $this->one('craft_equipment_instance', ['id' => $moved['instance_id']]); $wear = $this->one('craft_equipment_exposure', ['instance_id' => $moved['instance_id']]);
                    if ((int)$unit['durability'] < 1) throw new GameError('SHELTER_BROKEN', 'Шалаш износился. Размещение отменено.');
                    $protectedUntil = (int)$wear['settled_at'] + (int)ceil(((int)$unit['durability'] * 86400 - (int)$wear['remainder']) / (int)$wear['daily_wear']);
                    $this->db->createCommand()->insert('world_shelter_deployment', ['instance_id' => $unit['id'], 'active_instance_id' => $unit['id'], 'node_id' => $building['id'], 'plot_id' => $payload['node_id'], 'started_at' => (int)$wear['settled_at'], 'protected_until' => $protectedUntil, 'durability_at_start' => (int)$unit['durability'], 'wear_remainder' => (int)$wear['remainder'], 'daily_wear' => (int)$wear['daily_wear'], 'policy_version' => (int)$wear['policy_version'], 'operation_id' => $operation])->execute();
                    (new ShelterProtection($this->db))->open((int)$this->db->getLastInsertID(), (int)$unit['id'], $operation);
                }
                if ($action === 'fold') {
                    $now = max($now, (int)$this->one('craft_equipment_exposure', ['instance_id' => $moved['instance_id']])['settled_at']);
                    (new ShelterProtection($this->db))->close((int)$a['deployment']['id'], $now);
                    if ($a['lodging']) $this->closeLodging((int)$a['lodging']['id'], $now);
                    $this->db->createCommand()->update('world_shelter_deployment', ['ended_at' => $now, 'active_instance_id' => null], ['id' => $a['deployment']['id']])->execute();
                    $this->db->createCommand()->update('craft_storage', ['status' => 'retired'], ['id' => $a['row']['storage_id']])->execute();
                    (new WorldTree($this->db))->foldShelter((int)$a['deployment']['node_id']); $changed[] = (int)$a['deployment']['node_id'];
                }
            } elseif ($action === 'repair') {
                $repair = $terms['repair'];
                if (!$repair['available']) throw new GameError('SHELTER_REPAIR_UNAVAILABLE', implode(' ', $repair['reasons']));
                foreach ($repair['materials'] as $material) $inventory->change($user, $this->one('craft_item', ['id' => $material['item_id']]), -$material['quantity']);
                $restored = (new EquipmentExposure($this->db))->restore((int)$a['unit']['id'], $repair['restore'], $p['repair_at']);
                $now = (int)$restored['settled_at'];
                if ($a['deployment']) {
                    $protection = new ShelterProtection($this->db);
                    $protection->close((int)$a['deployment']['id'], $now);
                    $protection->open((int)$a['deployment']['id'], (int)$a['unit']['id'], $operation);
                    $changed[] = (int)$a['deployment']['node_id'];
                }
                $this->db->createCommand()->insert('world_shelter_repair', ['instance_id' => $a['unit']['id'], 'operation_id' => $operation, 'durability_before' => $repair['durability_before'], 'durability_after' => $repair['durability_after'], 'terms_json' => CanonicalJson::encode($terms), 'repaired_at' => $now])->execute();
                $bag = $inventory->backpack($user);
                if ($bag) $storages[] = (int)$bag['id'];
                $storages[] = (int)$a['row']['storage_id'];
                $this->db->createCommand()->update('craft_storage', ['revision' => new Expression('[[revision]]+1')], ['id' => $a['row']['storage_id']])->execute();
            } elseif ($action === 'lodge') {
                $this->db->createCommand()->insert('world_lodging_interval', ['actor_id' => $terms['actor_id'], 'active_actor_id' => $terms['actor_id'], 'deployment_id' => $a['deployment']['id'], 'active_deployment_id' => $a['deployment']['id'], 'started_at' => $now, 'operation_id' => $operation])->execute();
            } else $this->closeLodging((int)$a['lodging']['id'], $now);
            $result = ['changed_node_ids' => array_values(array_unique($changed)), 'changed_storage_ids' => array_values(array_unique($storages))];
            (new WorldTree($this->db))->audit($user, 'world.shelter.' . $action, 'Шалаш и назначение ночлега', $terms, $result + ['id' => $payload['node_id']], $operation);
            $bus->emit($operation, $user, 'world.shelter.' . $action, $result);
            return $result;
        });
    }
}
