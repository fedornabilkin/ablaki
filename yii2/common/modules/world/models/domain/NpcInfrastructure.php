<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\{Node, NodeFactory, NodeTemplate};
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage, StorageAccessPolicy};
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\support\{CanonicalJson, GameError, Locks};
use yii\db\{Connection, Expression, Query};

/** Explicit, repeatable starting content; never replaces a participant's property. */
final class NpcInfrastructure
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function policy(?int $world = null): ?array
    {
        if (!$this->db->schema->getTableSchema('world_npc_policy')) return null;
        $world = $world ?? EconomyRuntime::world($this->db);
        $policy = (new Query())->from('world_npc_policy')->where(['world_id' => $world, 'enabled' => 1])->one($this->db) ?: null;
        if ($policy && $world === EconomyRuntime::world($this->db)) {
            $revision = EconomyRuntime::current($this->db);
            $policy = array_replace($policy, $revision['profile']['npc'] ?? []);
        }
        return $policy;
    }
    public static function role(array $node): ?string
    {
        $settings = json_decode($node['settings_json'] ?? '{}', true) ?: [];
        return is_string($settings['npc_role'] ?? null) ? $settings['npc_role'] : null;
    }
    public function managed(array $node, ?array $roles = null): bool
    {
        $role = self::role($node);
        if (!$role || ($roles !== null && !in_array($role, $roles, true))) return false;
        $policy = $this->policy((int)$node['root_id']);
        if (!$policy || (int)$policy['bot_user_id'] !== (int)$node['owner_user_id']) return false;
        return (new Query())->from('user')->where(['id' => $policy['bot_user_id'], 'username' => 'bot'])->exists($this->db)
            && (new Query())->from('world_node_closure')->where(['ancestor_id' => $policy['site_node_id'], 'descendant_id' => $node['id']])->exists($this->db);
    }
    public function publicWorkshop(int $node): bool
    {
        $row = (new Query())->from('world_node')->where(['id' => $node, 'status' => 'active'])->one($this->db);
        return $row && $this->managed($row, ['workshop']);
    }
    public function nodes(array $policy): array
    {
        $rows = (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], 'c.descendant_id=n.id')
            ->where(['c.ancestor_id' => $policy['site_node_id'], 'n.owner_user_id' => $policy['bot_user_id'], 'n.status' => 'active'])->orderBy('n.id')->all($this->db);
        return array_values(array_filter($rows, static fn($row) => self::role($row) !== null));
    }
    public function ready(?array $policy = null): bool
    {
        $policy = $policy ?? $this->policy(); if (!$policy || !$policy['site_node_id']) return false;
        $nodes = array_column($this->nodes($policy), null, 'code');
        $codes = ['mine', 'forge', 'workshop', 'water', 'meadow', 'sand', 'clay', 'stone', 'forest', 'trap-1', 'trap-2', 'warehouse'];
        if (!isset($nodes['npc-bot-north-site'], $nodes['npc-bot-drilling'])) return false;
        foreach ($codes as $code) if (!isset($nodes['npc-bot-' . $code]) || ($code !== 'warehouse' && !isset($nodes['npc-bot-mini-' . $nodes['npc-bot-' . $code]['id']]))) return false;
        if ((new Query())->from('world_node')->where(['root_id' => $policy['world_id'], 'status' => 'active', 'node_type' => ['WORLD', 'REGION', 'SETTLEMENT']])
            ->andWhere(['or', ['owner_user_id' => null], ['<>', 'owner_user_id', $policy['bot_user_id']]])->exists($this->db)) return false;
        $stations = (new Query())->select(['s.node_id', 'd.code'])->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')
            ->where(['s.node_id' => [$nodes['npc-bot-forge']['id'], $nodes['npc-bot-workshop']['id']], 's.owner_user_id' => $policy['bot_user_id'], 's.kind' => 'placement', 's.status' => 'active', 'i.user_id' => $policy['bot_user_id']])->andWhere(['>', 'i.item_quantity', 0])->all($this->db);
        $present = []; foreach ($stations as $station) $present[$station['node_id'] . ':' . $station['code']] = true;
        return isset($present[$nodes['npc-bot-forge']['id'] . ':classic-furnace'], $present[$nodes['npc-bot-forge']['id'] . ':classic-anvil'], $present[$nodes['npc-bot-workshop']['id'] . ':classic-bench']);
    }
    public function install(int $author): array
    {
        if (!\Yii::$app->authManager->checkAccess($author, 'p-admin')) throw new GameError('NPC_ADMIN_REQUIRED', 'Настройка NPC доступна администратору.', 403);
        $bot = (new Query())->from('user')->where(['username' => 'bot'])->one($this->db);
        if (!$bot) throw new GameError('NPC_ACCOUNT_REQUIRED', 'Нужен существующий пользователь с логином bot.', 409);
        if (!$this->db->schema->getTableSchema('world_npc_policy')) throw new GameError('SCHEMA_NOT_READY', 'Сначала установите схему NPC.', 503);
        $world = EconomyRuntime::world($this->db); $existing = $this->policy($world);
        if ($existing && $this->ready($existing) && $this->storageLayoutReady($existing)) return ['changed' => false, 'world_id' => $world, 'site_node_id' => (int)$existing['site_node_id']];
        return $this->db->transaction(function () use ($author, $bot, $world) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $city = Node::findOne(['code' => 'north-city', 'root_id' => $world, 'status' => 'active']);
            if (!$city) throw new GameError('NPC_CITY_REQUIRED', 'В действующем мире не найден Североград.', 409);
            $configured = (new Query())->from('world_npc_policy')->where(['world_id' => $world])->one($this->db);
            if ($configured && !(int)$configured['enabled']) throw new GameError('NPC_POLICY_DISABLED', 'Политика NPC отключена администратором.', 409);
            $policy = $this->policy($world);
            if ($policy && $this->ready($policy) && $this->storageLayoutReady($policy)) return ['changed' => false, 'world_id' => $world, 'site_node_id' => (int)$policy['site_node_id']];
            if (!$policy) {
                $this->db->createCommand()->insert('world_npc_policy', ['world_id' => $world, 'bot_user_id' => $bot['id'], 'city_node_id' => $city->id,
                    'created_by' => $author, 'created_at' => time()])->execute();
                $policy = $this->policy($world);
            }
            if ((int)$policy['bot_user_id'] !== (int)$bot['id']) throw new GameError('NPC_OWNER_CHANGED', 'Владелец политики NPC требует сверки.', 409);
            $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $author, 'type' => 'world.npc.install', 'created_at' => time()] + EconomyRuntime::operationContext($this->db, ['node_id' => $world]))->execute();
            foreach (Node::find()->where(['root_id' => $world, 'node_type' => ['WORLD', 'REGION', 'SETTLEMENT'], 'status' => 'active'])->all() as $public) {
                if ((int)$public->owner_user_id === (int)$bot['id']) continue;
                if ((new Query())->from('craft_storage')->where(['node_id' => $public->id])->andWhere(['<>', 'owner_user_id', $bot['id']])->exists($this->db))
                    throw new GameError('NPC_PROPERTY_REVIEW_REQUIRED', 'На публичной территории есть чужое хранилище. Сначала сохраните доступ владельцу.', 409);
                $before = $public->getAttributes();
                // Explicit administrative ownership transfer; ordinary editing keeps ownership fixed.
                if ($this->db->createCommand()->update('world_node', ['owner_user_id' => (int)$bot['id'], 'revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $public->id])->execute() !== 1) throw new \RuntimeException('NPC territory ownership write failed.');
                $public->refresh();
                (new WorldTree($this->db))->audit($author, 'world.npc.ownership', 'Публичная территория передана основному NPC bot', $before, $public->getAttributes(), $operation);
                (new EconomyHierarchy($this->db))->provision((int)$public->id, $operation);
            }
            $city->refresh();
            $site = $this->object('starter-site', $city, (int)$bot['id'], 'Стоянка бота', 'npc-bot-north-site', 'camp', $author, $operation);
            $this->db->createCommand()->update('world_npc_policy', ['site_node_id' => $site->id], ['id' => $policy['id']])->execute();
            $specs = [
                ['mine', 'Шахта бота', 'mine', 'source'], ['forge', 'Кузница бота', 'forge', 'workshop'],
                ['workshop-building', 'Мастерская бота', 'workshop', 'workshop'], ['water-source', 'Источник воды', 'water', 'source'],
                ['meadow-plot', 'Луговой участок', 'meadow', 'source'], ['sand-plot', 'Песчаный участок', 'sand', 'source'],
                ['clay-plot', 'Глиняный участок', 'clay', 'source'], ['stone-plot', 'Каменистый участок', 'stone', 'source'],
                ['forest-plot', 'Лесной участок', 'forest', 'source'], ['trap', 'Ловушка бота №1', 'trap-1', 'source'],
                ['trap', 'Ловушка бота №2', 'trap-2', 'source'], ['warehouse', 'Склад бота', 'warehouse', 'warehouse'],
            ];
            $objects = [];
            foreach ($specs as [$template, $name, $code, $role]) {
                $node = $this->object($template, $site, (int)$bot['id'], $name, 'npc-bot-' . $code, $role, $author, $operation); $objects[$code] = $node;
                if ($role !== 'warehouse') $this->mini($node, (int)$bot['id'], $author, $operation);
            }
            $this->retireWarehouseMini($objects['warehouse'], (int)$bot['id'], $author, $operation);
            $drill = $this->object('drilling-rig', $objects['mine'], (int)$bot['id'], 'Буровая установка', 'npc-bot-drilling', 'equipment', $author, $operation);
            $this->stations($objects['forge'], ['classic-furnace', 'classic-anvil'], (int)$bot['id'], $operation);
            $this->stations($objects['workshop'], ['classic-bench'], (int)$bot['id'], $operation);
            $policy = $this->policy($world);
            $maintenance = new NpcMaintenance($this->db); $maintenance->refresh($policy, $operation);
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            return ['changed' => true, 'world_id' => $world, 'bot_user_id' => (int)$bot['id'], 'site_node_id' => (int)$site->id,
                'objects' => array_map(static fn($node) => (int)$node->id, $objects), 'drilling_node_id' => (int)$drill->id,
                'funding' => 'existing_funds', 'money_created_cr' => '0.0000'];
        });
    }
    private function object(string $template, Node $parent, int $bot, string $name, string $code, string $role, int $author, string $operation): Node
    {
        $node = Node::findOne(['code' => $code]);
        if ($node) {
            if ((int)$node->owner_user_id !== $bot || (int)$node->parent_id !== (int)$parent->id || $node->status !== 'active') throw new GameError('NPC_OBJECT_COLLISION', 'Стартовый объект NPC требует сверки: ' . $code, 409);
            return $node;
        }
        $cell = $this->cell($parent, $operation);
        $definition = NodeTemplate::requireOne(['code' => $template]);
        $node = NodeFactory::create($definition, $parent, $bot, $name, $code, $cell, 'constructing');
        $seconds = max(1, (int)$definition->build_seconds);
        $this->db->createCommand()->insert('world_build', ['node_id' => $node->id, 'parent_id' => $parent->id, 'owner_user_id' => $bot,
            'required_seconds' => $seconds, 'worked_seconds' => $seconds, 'labor_budget' => '0.0000', 'materials_json' => '{}', 'status' => 'completed',
            'revision' => 1, 'created_at' => time(), 'finished_at' => time()])->execute();
        $node->status = 'active'; $node->condition = $node->max_condition;
        if ($node->node_type === 'BUILDING') $node->operational_status = 'active';
        $settings = json_decode($node->settings_json ?: '{}', true) ?: [];
        $settings['npc_role'] = $role; $settings['npc_public_work'] = in_array($role, ['source', 'workshop'], true);
        if ($role === 'source') { $settings['production_enabled'] = true; $settings['hired_extraction'] = ['enabled' => true, 'reward_cr' => '1.0000']; }
        if ($role === 'warehouse') { $settings['storage_capacity'] = 100; $settings['allow_sales'] = true; }
        $node->settings_json = CanonicalJson::encode($settings); $node->persist();
        (new PlacementProvisioner($this->db))->openObjectMap($node, $operation);
        (new PlacementProvisioner($this->db))->interior($node);
        (new EconomyHierarchy($this->db))->provision((int)$node->id, $operation);
        if ($role === 'warehouse') $this->stockpile($node, $bot, 100);
        (new WorldTree($this->db))->audit($author, 'world.npc.construct', 'Стартовая инфраструктура NPC', [], $node->getAttributes(), $operation);
        $node->refresh();
        return $node;
    }
    private function cell(Node $parent, string $operation): array
    {
        $occupied = [];
        foreach ((new Query())->select(['position_x', 'position_y', 'footprint_json'])->from('world_node')->where(['parent_id' => $parent->id])->andWhere(['<>', 'status', 'archived'])->all($this->db) as $row)
            foreach (WorldMapGeometry::cells($row['footprint_json'], (int)$row['position_x'], (int)$row['position_y']) as $cell) $occupied[$cell['x'] . ':' . $cell['y']] = true;
        foreach ((new PlacementProvisioner($this->db))->equipmentCells((int)$parent->id) as $cell) $occupied[$cell['x'] . ':' . $cell['y']] = true;
        $cells = [];
        for ($y = (int)$parent->map_origin_y; $y < (int)$parent->map_origin_y + (int)$parent->map_height; $y++)
            for ($x = (int)$parent->map_origin_x; $x < (int)$parent->map_origin_x + (int)$parent->map_width; $x++) if (!isset($occupied[$x . ':' . $y])) $cells[] = compact('x', 'y');
        usort($cells, static fn($a, $b) => [abs($a['x']) + abs($a['y']), $a['y'], $a['x']] <=> [abs($b['x']) + abs($b['y']), $b['y'], $b['x']]);
        if (!$cells) throw new GameError('NPC_MAP_FULL', 'Нет свободной ячейки для стартовой инфраструктуры NPC.', 409);
        $cell = $cells[0]; $where = ['parent_id' => $parent->id] + $cell;
        if ((new Query())->from('world_map_cell')->where($where)->exists($this->db))
            $this->db->createCommand()->update('world_map_cell', ['state' => 'open', 'updated_at' => time()], $where)->execute();
        else $this->db->createCommand()->insert('world_map_cell', $where + ['state' => 'open', 'price' => '0.0000', 'operation_id' => $operation, 'created_at' => time(), 'updated_at' => time()])->execute();
        return $cell;
    }
    private function mini(Node $parent, int $bot, int $author, string $operation): void
    {
        $node = $this->object('mini-storage-level-' . ((int)$parent->hierarchy_level + 1), $parent, $bot, 'Мини-склад: ' . $parent->name,
            'npc-bot-mini-' . $parent->id, 'store', $author, $operation);
        $settings = json_decode($node->settings_json, true); $settings['storage_capacity'] = MiniStorage::CAPACITY; $settings['allow_sales'] = true;
        $node->settings_json = CanonicalJson::encode($settings); $node->persist();
        $this->stockpile($node, $bot, MiniStorage::CAPACITY);
    }
    private function stockpile(Node $node, int $bot, int $capacity): void
    {
        $id = BuildingFacilities::stockpile($this->db, (int)$node->id, $bot, 'warehouse');
        if ($node->node_type === 'CHEST' && (new Query())->from('craft_inventory')->where(['storage_id' => $id])->andWhere(['>', 'item_quantity', 0])
            ->andWhere(['or', ['>', 'slot', $capacity], ['>', 'item_quantity', MiniStorage::STACK_LIMIT]])->exists($this->db))
            throw new GameError('NPC_STORAGE_REVIEW_REQUIRED', 'В старом мини-складе есть запасы сверх 5×100. Сначала перенесите излишки в большой склад; товары сохранены.', 409);
        $this->db->createCommand()->update('craft_storage', ['capacity' => $capacity], ['id' => $id])->execute();
        $this->db->createCommand()->update('world_warehouse_policy', ['initial_capacity' => $capacity, 'max_capacity' => $node->node_type === 'CHEST' ? $capacity : max(200, $capacity)], ['storage_id' => $id])->execute();
    }
    /** Checked independently from installation readiness so a data correction keeps the warm release path. */
    private function storageLayoutReady(array $policy): bool
    {
        $nodes = array_column($this->nodes($policy), null, 'id');
        foreach ($nodes as $node) if (self::role($node) === 'store') {
            if (isset($nodes[$node['parent_id']]) && self::role($nodes[$node['parent_id']]) === 'warehouse') return false;
            if ((json_decode($node['settings_json'] ?: '{}', true)['storage_capacity'] ?? null) !== MiniStorage::CAPACITY) return false;
            $storage = (new Query())->select(['s.*', 'p.max_capacity'])->from(['s' => 'craft_storage'])->leftJoin(['p' => 'world_warehouse_policy'], 'p.storage_id=s.id')
                ->where(['s.node_id' => $node['id'], 's.owner_user_id' => $policy['bot_user_id'], 's.kind' => 'stockpile', 's.status' => 'active'])->one($this->db);
            if (!$storage || (int)$storage['capacity'] !== MiniStorage::CAPACITY || (int)$storage['max_capacity'] !== MiniStorage::CAPACITY) return false;
        }
        return true;
    }
    private function retireWarehouseMini(Node $warehouse, int $bot, int $author, string $operation): void
    {
        foreach (Node::find()->where(['parent_id' => $warehouse->id, 'node_type' => 'CHEST', 'status' => 'active', 'owner_user_id' => $bot])->all() as $mini) {
            if (!$this->managed($mini->getAttributes(), ['store'])) continue;
            $references = array_diff((new WorldNodeEditor($this->db, new WorldFlags($this->db, \Yii::$app->getModule('world'))))->usage((int)$mini->id, true, true), ['economy_subject']);
            $storageIds = (new Query())->select('id')->from('craft_storage')->where(['node_id' => $mini->id])->column($this->db);
            $accepted = $storageIds && (new Query())->from('world_source_work')->where(['target_storage_id' => $storageIds])->andWhere(['not', ['active_key' => null]])->exists($this->db);
            // Never archive property, funds, reservations or accepted work as a side effect of a layout repair.
            if (\common\modules\world\models\NodeUsage::hasAssets((int)$mini->id) || $references || $accepted
                || $mini->getChildren()->andWhere(['<>', 'status', 'archived'])->exists())
                throw new GameError('NPC_STORAGE_REVIEW_REQUIRED', 'Мини-склад внутри большого склада ещё используется. Завершите операции и перенесите запасы; имущество и средства сохранены.', 409);
            $before = $mini->getAttributes();
            $this->db->createCommand()->update('craft_storage', ['status' => 'retired', 'capacity' => 0, 'revision' => new Expression('[[revision]]+1')], ['node_id' => $mini->id, 'owner_user_id' => $bot, 'status' => 'active'])->execute();
            if ($this->db->createCommand()->update('world_node', ['status' => 'archived', 'revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $mini->id, 'status' => 'active'])->execute() !== 1) throw new \RuntimeException('NPC redundant storage write failed.');
            $mini->refresh();
            (new WorldTree($this->db))->audit($author, 'world.npc.storage-layout', 'Пустой мини-склад внутри большого склада убран в архив', $before, $mini->getAttributes(), $operation);
        }
    }
    private function stations(Node $node, array $codes, int $bot, string $operation): void
    {
        $placement = (new Query())->from('craft_storage')->where(['identity_key' => 'placement:node:' . $node->id])->one($this->db);
        $store = new CraftStorage($this->db); $store->operationId = $operation;
        $inventory = new CanonicalInventory($store); $target = (new StorageAccessPolicy($this->db))->storage($bot, (int)$placement['id'], true);
        foreach ($codes as $code) {
            $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->one($this->db);
            if (!$item) throw new GameError('NPC_STATION_REQUIRED', 'Не установлена станция: ' . $code, 409);
            if ((new Query())->from('craft_inventory')->where(['storage_id' => $target['id'], 'item_id' => $item['id']])->andWhere(['>', 'item_quantity', 0])->exists($this->db)) continue;
            $plan = $inventory->planCraft([], $target, [], [], $item, 1);
            if (!$plan['output_fits']) throw new GameError('NPC_STATION_SPACE', 'Нет места для станции NPC.', 409);
            $cell = $this->cell($node, $operation);
            $position = ($cell['y'] - (int)$node->map_origin_y) * (int)$node->map_width + $cell['x'] - (int)$node->map_origin_x + 1;
            if (count($plan['grant']) !== 1 || $plan['grant'][0]['inventory_id'] !== null) throw new \LogicException('NPC station must be a single new placed instance.');
            $plan['grant'][0]['position'] = $position;
            $inventory->applyCraft($bot, $plan, $target, [], $item, 'npc.station.installed');
        }
    }
}
