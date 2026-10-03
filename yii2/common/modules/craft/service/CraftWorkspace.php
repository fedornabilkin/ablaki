<?php
namespace common\modules\craft\service;

use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldQuery;
use common\modules\world\service\WorldFlags;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Explicit, owner-only manual production. Public tariffs and NPC reservations are separate stages. */
class CraftWorkspace
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function context(int $user, int $node): array
    {
        $this->flags->requireFlags(['world_read', 'storage_v2']);
        $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->record($node);
        if ((int)$place['owner_user_id'] !== $user || $place['status'] !== 'active' || !in_array($place['node_type'], ['PLOT', 'BUILDING', 'ROOM'], true)) throw new GameError('WORKSPACE_UNAVAILABLE', 'Выберите собственную действующую площадку или помещение.');
        if (!(new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $place['root_id']])->exists($this->db)) throw new GameError('WORLD_MEMBERSHIP_REQUIRED', 'Сначала вступите в этот мир.');
        if ((new Query())->from(['b' => 'world_building'])->innerJoin(['p' => 'world_node_closure'], '[[p.ancestor_id]]=[[b.node_id]]')->where(['p.descendant_id' => $node])->andWhere(['or', ['<>', 'b.operational_status', 'active'], ['<', 'b.condition', 1]])->exists($this->db)) throw new GameError('WORKSPACE_UNAVAILABLE', 'Постройка недоступна для работы.');
        $place['id'] = (int)$place['id']; $place['revision'] = (int)$place['revision'];
        return $place;
    }
    private function store(int $node): CraftStorage { $store = new CraftStorage($this->db); $store->workspaceNodeId = $node; return $store; }
    private function storage(int $user, int $id, int $node, bool $incoming): array
    {
        $row = (new StorageAccessPolicy($this->db))->storage($user, $id, $incoming);
        if (!in_array($row['kind'], ['backpack', 'chest', 'stockpile'], true)) throw new GameError('INVALID_CRAFT_STORAGE', 'Материалы и результат должны находиться в рюкзаке, сундуке или на складе.');
        $location = $row;
        if ($row['kind'] === 'chest') {
            $outer = (new Query())->from('craft_inventory')->where(['id' => $row['container_inventory_id'], 'user_id' => $user])->one($this->db);
            $location = (new StorageAccessPolicy($this->db))->storage($user, (int)$outer['storage_id'], true);
        }
        if ($location['kind'] !== 'backpack' && (!in_array($location['kind'], ['placement', 'stockpile'], true) || !in_array((int)$location['node_id'], WorkspaceScope::nodes($this->db, $node), true))) throw new GameError('REMOTE_CRAFT_STORAGE', 'Выберите хранилище в этом месте или в своём рюкзаке.');
        return $row;
    }
    private function roles(array $recipe): array
    {
        $roles = [];
        foreach ((new Query())->from('craft_recipe_tool')->where(['recipe_id' => $recipe['id']])->orderBy(['item_id' => SORT_ASC])->all($this->db) as $tool) $roles[(int)$tool['item_id']] = false;
        if ($recipe['station_id'] !== null) {
            $station = (new Query())->from('craft_station')->where(['id' => $recipe['station_id'], 'active' => 1])->one($this->db);
            if (!$station) throw new GameError('STATION_UNAVAILABLE', 'Станция рецепта недоступна.');
            if ($station['item_id'] !== null) $roles[(int)$station['item_id']] = true;
        }
        ksort($roles); return $roles;
    }
    public function state(int $user, int $node, ?int $recipeId): array
    {
        $place = $this->context($user, $node); $store = $this->store($node); $craft = new Crafting($store);
        $recipes = []; $selected = null;
        $catalog = (new Query())->from('craft_recipe')->where(['active' => 1])->orderBy(['id' => SORT_ASC])->limit(2000)->all($this->db);
        $outputs = $catalog ? (new Query())->from('craft_item')->where(['id' => array_values(array_unique(array_column($catalog, 'item_id'))), 'active' => 1])->indexBy('id')->all($this->db) : [];
        $requirements = $craft->requirementsBatch($user, $catalog);
        foreach ($catalog as $recipe) {
            $output = $outputs[$recipe['item_id']] ?? null; if (!$output) continue;
            if (($recipeId === null && $selected === null) || (int)$recipe['id'] === $recipeId) $selected = $recipe;
            $recipes[] = ['id' => (int)$recipe['id'], 'name' => trim($output['name']), 'quantity' => (int)$recipe['output_quantity'], 'locked_reasons' => $requirements[(int)$recipe['id']]];
        }
        if ($recipeId !== null && (!$selected || (int)$selected['id'] !== $recipeId)) throw new GameError('RECIPE_UNAVAILABLE', 'Рецепт недоступен.');
        $equipment = [];
        foreach ($selected ? $this->roles($selected) : [] as $item => $station) {
            $definition = (new Query())->from('craft_item')->where(['id' => $item])->one($this->db);
            $equipment[] = ['item_id' => $item, 'name' => trim($definition['name']), 'is_station' => $station, 'candidates' => (new StationResolver($store))->candidates($user, $item, $station)];
        }
        $storages = [];
        foreach ((new WorldStorage($this->db, $this->flags))->list($user, $node)['items'] as $header) {
            try {
                $this->storage($user, $header['id'], $node, false); $incoming = true;
                try { $this->storage($user, $header['id'], $node, true); } catch (\yii\web\HttpException $e) { $incoming = false; } catch (GameError $e) { $incoming = false; }
                $storages[] = $header + ['output_allowed' => $incoming];
            } catch (\yii\web\HttpException $e) { continue; } catch (GameError $e) { continue; }
        }
        $recipes = (new WorkspaceAvailability($this->db))->recipes($user, $store, $catalog, $recipes, $storages);
        return ['node_id' => $node, 'name' => $place['name'], 'recipe_id' => $selected ? (int)$selected['id'] : null, 'recipes' => $recipes, 'equipment' => $equipment, 'storages' => $storages, 'writable' => $this->flags->capabilities()['world_write'], 'server_time' => time()];
    }
    private function prepare(int $user, array $payload): array
    {
        $place = $this->context($user, $payload['node_id']); $store = $this->store($place['id']); $craft = new Crafting($store);
        $input = $craft->recipeInputs($user, $payload['recipe_id'], $payload['quantity']);
        $sources = []; foreach ($payload['source_storage_ids'] as $id) $sources[] = $this->storage($user, $id, $place['id'], false);
        $target = $this->storage($user, $payload['output_storage_id'], $place['id'], true);
        $selected = []; $reasons = []; $wear = new EquipmentExposure($this->db);
        $roles = $this->roles($input['recipe']); $ids = $payload['equipment_instance_ids'];
        if (count($roles) !== count($ids)) throw new GameError('EQUIPMENT_REQUIRED', 'Выберите по одному экземпляру каждого инструмента и станции.');
        foreach ($roles as $item => $station) {
            $match = null;
            foreach ($ids as $id) { $candidate = (new StationResolver($store))->select($user, $item, $station, $id); if ($candidate) { $match = $candidate; break; } }
            if (!$match) throw new GameError('EQUIPMENT_UNAVAILABLE', 'Выбранное оборудование недоступно в этом месте.');
            $match['wear'] = $wear->cost($match, 0, $payload['quantity'], $station);
            if ($match['durability'] < max(1, $match['wear'])) $reasons[] = 'Недостаточно прочности выбранного оборудования.';
            $selected[] = $match;
        }
        $store->protectedInstances = array_column($selected, 'instance_id');
        $plan = (new CanonicalInventory($store))->planCraft($sources, $target, $input['required'], $input['items'], $input['output'], (int)$input['recipe']['output_quantity'] * $payload['quantity']);
        foreach ($plan['materials'] as $material) if (!$material['available']) $reasons[] = 'Не хватает: ' . $material['name'];
        if (!$plan['output_fits']) $reasons[] = 'В выбранном хранилище недостаточно места для результата или оно не подходит для предмета.';
        $meta = (new Query())->from('craft_meta')->where(['id' => 1])->one($this->db);
        $cost = (int)$meta['charge_credits'] === 1 ? (int)$input['recipe']['cost_credits'] * $payload['quantity'] : 0;
        $efficiency = (new \common\modules\world\service\NightWorkEfficiency($this->db))->basisPoints($user);
        $baseExperience = (int)$input['recipe']['experience'] * $payload['quantity'];
        $experience = $baseExperience ? max(1, intdiv($baseExperience * $efficiency, 10000)) : 0;
        $person = (new Query())->from('persone')->where(['user_id' => $user])->one($this->db);
        if (!$person || (float)$person['credit'] < $cost) $reasons[] = 'Не хватает кредитов.';
        if ($cost) (new CraftInventory($store))->requireTransactionalBalance();
        $revisions = ['node:' . $place['id'] => $place['revision'], 'catalog' => (int)$meta['revision']];
        foreach (array_merge($sources, [$target]) as $storage) $revisions['storage:' . $storage['id']] = (int)$storage['revision'];
        foreach ($selected as $unit) {
            $instance = (new Query())->from('craft_equipment_instance')->where(['id' => $unit['instance_id']])->one($this->db);
            $revisions['instance:' . $instance['id']] = (int)$instance['revision'];
        }
        $terms = ['materials' => $plan['materials'], 'consume' => $plan['consume'], 'grant' => $plan['grant'], 'equipment' => $selected,
            'output' => ['item_id' => (int)$input['output']['id'], 'name' => trim($input['output']['name']), 'quantity' => (int)$input['recipe']['output_quantity'] * $payload['quantity'], 'storage_id' => (int)$target['id'], 'fits' => $plan['output_fits']],
            'price' => $cost . '.0000', 'currency' => 'Cr', 'experience' => $experience, 'work_efficiency_bps' => $efficiency,
            'reasons' => array_values(array_unique($reasons))];
        return compact('store', 'input', 'sources', 'target', 'plan', 'selected', 'cost', 'revisions', 'terms');
    }
    public function preview(int $user, array $payload): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.workspace.craft', $payload, function () use ($user, $payload) {
            $p = $this->prepare($user, $payload); return ['revisions' => $p['revisions'], 'terms' => $p['terms']];
        });
    }
    public function craft(int $user, string $key, array $payload, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.workspace.craft', $payload, $quote, $revisions, function (array $input, array $terms, string $operation) use ($user, $bus) {
            $p = $this->prepare($user, $input);
            if ($p['terms']['reasons']) throw new GameError('CRAFT_UNAVAILABLE', implode(' ', $p['terms']['reasons']));
            // Natural wear can progress during the quote TTL; require fresh consent if visible terms changed.
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('CRAFT_CHANGED', 'Условия изготовления изменились. Повторите расчёт.');
            $p['store']->operationId = $operation;
            foreach ($p['selected'] as $unit) (new EquipmentExposure($this->db))->use($unit['instance_id'], 0, $input['quantity'], $unit['is_station']);
            (new CanonicalInventory($p['store']))->applyCraft($user, $p['plan'], $p['target'], $p['input']['items'], $p['input']['output']);
            $message = (new Crafting($p['store']))->completeRecipe($user, $p['input']['recipe'], $p['input']['output'], $input['quantity'], $p['cost']);
            $storages = array_merge(array_column($p['sources'], 'id'), [$p['target']['id']], array_column($p['selected'], 'storage_id'));
            return ['message' => $message, 'changed_node_ids' => [$input['node_id']], 'changed_storage_ids' => array_values(array_unique(array_map('intval', $storages)))];
        });
    }
}
