<?php
namespace common\modules\world\models\domain;

use common\modules\world\domain\DevelopmentRoute;
use yii\db\{Connection, Query};

/** Projects the framework-free guide onto the current player's world. */
final class WorldDevelopment
{
    public function __construct(private readonly Connection $db) {}

    public function state(int $user): array
    {
        $world = EconomyRuntime::world($this->db);
        $member = (new Query())->from('world_membership')->where(['world_id' => $world, 'user_id' => $user])->one($this->db);
        $site = $member ? (int)$member['starter_site_id'] : null;
        $nodes = (new Query())->select(['n.id', 't.code'])->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')
            ->where(['n.owner_user_id' => $user, 'n.root_id' => $world, 'n.status' => 'active'])->orderBy('n.id')->all($this->db);
        $byCode = [];
        foreach ($nodes as $node) if (!isset($byCode[$node['code']]) && NodeAvailability::activePath($this->db, (int)$node['id']))
            $byCode[$node['code']] = (int)$node['id'];
        if ($site) {
            $byCode['starter-site'] = $site;
            $byCode['city'] = (int)(new Query())->select('parent_id')->from('world_node')->where(['id' => $site])->scalar($this->db);
        }
        $npc = (new Query())->select('id')->from('world_node')->where(['root_id' => $world, 'code' => 'npc-bot-mine', 'status' => 'active'])->scalar($this->db);
        if ($npc) $byCode['npc-mine'] = (int)$npc;
        $links = ['map:world' => '/world'];
        foreach (DevelopmentRoute::stages() as $stage) foreach ($stage['steps'] as $step) {
            if ($step['action'] === 'character') { $links['character:' . $step['node']] = '/world/character'; continue; }
            $node = $byCode[$step['node']] ?? $site;
            $links[$step['action'] . ':' . $step['node']] = !$node ? '/world' : ($step['action'] === 'craft' ? '/world/workspace/' : '/world/nodes/') . $node;
        }
        return DevelopmentRoute::progress((new WorldProgress($this->db))->quantities($user), $links);
    }
}
