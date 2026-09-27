<?php
namespace common\modules\world\service;

use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

class WorldOnboarding
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function world(): array
    {
        $registry = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
        if (!$registry || !$registry['active_world_id']) throw new GameError('WORLD_NOT_READY', 'Мир ещё не открыт.', 503);
        return $registry;
    }
    public function state(int $user): array
    {
        $this->flags->requireFlag('world_read'); $registry = $this->world();
        $membership = (new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $registry['active_world_id']])->one($this->db);
        $nightPolicy = (new WorldNights($this->db, $this->flags))->policy((int)$registry['active_world_id']);
        $grace = $membership ? ($nightPolicy ? max((int)$membership['joined_at'], (int)$nightPolicy['activated_at']) + (int)$nightPolicy['day_seconds'] : (int)$membership['grace_until']) : null;
        return ['world_id' => (int)$registry['active_world_id'], 'joined' => (bool)$membership, 'starter_site_id' => $membership ? (int)$membership['starter_site_id'] : null,
            'joined_at' => $membership ? (int)$membership['joined_at'] : null, 'grace_until' => $grace,
            'server_time' => time(), 'join_available' => !$membership && $this->flags->capabilities()['world_write']];
    }
    public function preview(int $user, int $settlement): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.join', ['settlement_id' => $settlement], function (array $payload) use ($user) {
            $registry = $this->world();
            $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($payload['settlement_id']);
            if ($node['type'] !== 'SETTLEMENT' || $node['root_id'] !== (int)$registry['active_world_id'] || $node['status'] !== 'active') throw new GameError('INVALID_SETTLEMENT', 'Выберите доступное поселение.', 422);
            if ($this->state($user)['joined']) throw new GameError('ALREADY_JOINED', 'Вы уже вступили в этот мир.');
            $nightPolicy = (new WorldNights($this->db, $this->flags))->policy((int)$registry['active_world_id']);
            return ['revisions' => ['registry' => (int)$registry['content_revision'], 'node:' . $node['id'] => $node['revision']],
                'terms' => ['settlement_id' => $node['id'], 'world_id' => (int)$registry['active_world_id'], 'price' => '0.0000', 'currency' => 'Cr', 'right' => 'starter-campsite-use', 'items' => [], 'grace_seconds' => $nightPolicy ? (int)$nightPolicy['day_seconds'] : 86400]];
        });
    }
    public function join(int $user, string $key, int $settlement, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.join', ['settlement_id' => $settlement], $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $bus) {
            if ($this->state($user)['joined']) throw new GameError('ALREADY_JOINED', 'Вы уже вступили в этот мир.');
            $tree = new WorldTree($this->db); $parent = $tree->get($payload['settlement_id']);
            if ($parent['node_type'] !== 'SETTLEMENT' || $parent['status'] !== 'active' || (int)$parent['root_id'] !== (int)$terms['world_id'] || $parent['visibility'] !== 'public') throw new GameError('INVALID_SETTLEMENT', 'Поселение больше недоступно.');
            (new ActorResolver($this->db))->player($user);
            $code = 'camp-' . $user . '-' . $terms['world_id'];
            $site = $tree->create(['code' => $code, 'slug' => $code, 'node_type' => 'PLOT', 'name' => 'Стоянка', 'parent_id' => $payload['settlement_id'], 'owner_user_id' => $user, 'visibility' => 'private'], ['plot_kind' => 'campsite', 'area' => 4, 'allow_building' => 1]);
            $this->db->createCommand()->insert('world_membership', ['user_id' => $user, 'world_id' => $terms['world_id'], 'starter_site_id' => $site['id'], 'joined_at' => time(), 'grace_until' => time() + (int)$terms['grace_seconds']])->execute();
            (new WorldNights($this->db, $this->flags))->joined((new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $terms['world_id']])->one($this->db));
            (new PlacementProvisioner($this->db))->campsite($user, (int)$site['id']);
            $tree->audit($user, 'world.join', 'Однократное предоставление права стоянки', [], $site, $operation);
            $bus->emit($operation, $user, 'world.joined', ['world_id' => (int)$terms['world_id'], 'starter_site_id' => (int)$site['id']]);
            return ['onboarding' => $this->state($user), 'node' => (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node((int)$site['id']), 'changed_node_ids' => [(int)$site['id'], $payload['settlement_id']]];
        });
    }
}
