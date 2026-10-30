<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CraftInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\ProductionReservations;
use yii\db\Connection;
use yii\db\Query;

/** The same read model drives buttons and the command's locked revalidation. */
class MapExploration
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public static function requiredLevel(int $x, int $y): int { return max(1, min(3, max(abs($x), abs($y)))); }
    public static function predecessors(int $x, int $y): array
    {
        $ring = max(abs($x), abs($y)); $points = [];
        if ($ring < 2) return $points;
        if (abs($x) === $ring) $points[] = ['x' => $x - ($x < 0 ? -1 : 1), 'y' => $y];
        if (abs($y) === $ring) $points[] = ['x' => $x, 'y' => $y - ($y < 0 ? -1 : 1)];
        return $points;
    }
    public function requirePredecessors(int $node, int $x, int $y): void
    {
        $missing = [];
        foreach (self::predecessors($x, $y) as $point) {
            if (!(new Query())->from('world_map_cell')->where(['parent_id' => $node, 'state' => ['open', 'discovered']] + $point)->exists($this->db)) $missing[] = $point;
        }
        if ($missing) throw new \common\modules\world\support\GameError('MAP_PREDECESSOR_REQUIRED', 'Сначала исследуйте соседние ячейки со стороны центра; для диагонали нужны обе стороны.', 422, ['required_cells' => $missing]);
    }
    public function state(array $node, int $user): array
    {
        $commonObjects = isset($this->db->schema->getTableSchema('world_node')->columns['hierarchy_level']);
        if ($commonObjects) {
            // The common object model has the same map rights at every level.
            $allowed = $user > 0 && (int)$node['owner_user_id'] === $user && $node['status'] === 'active'
                && \common\modules\world\models\Node::visibleTo($user)->andWhere(['id' => $node['id']])->exists();
        } else {
        $allowed = (int)$node['owner_user_id'] === $user && $node['status'] === 'active'
            && in_array($node['node_type'], ['PLOT', 'BUILDING', 'ROOM'], true)
            && (new Query())->from(['path' => 'world_node_closure'])->innerJoin(['p' => 'world_plot'], '[[p.node_id]]=[[path.ancestor_id]]')
                ->innerJoin(['n' => 'world_node'], '[[n.id]]=[[p.node_id]]')
                ->where(['path.descendant_id' => $node['id'], 'p.plot_kind' => 'campsite', 'n.owner_user_id' => $user, 'n.status' => 'active'])->exists($this->db);
        if ($node['node_type'] === 'PLOT' && (new Query())->from('world_plot')->where(['node_id' => $node['id'], 'plot_kind' => 'garden'])->exists($this->db)) $allowed = false;
        if ($allowed && (new WorldTree($this->db))->isShelter((int)$node['id'])) $allowed = false;
        if ($allowed && (new Query())->from(['path' => 'world_node_closure'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[path.ancestor_id]]')
            ->where(['path.descendant_id' => $node['id'], 'n.node_type' => ['PLOT', 'BUILDING', 'ROOM']])
            ->andWhere(['or', ['<>', 'n.status', 'active'], ['<>', 'n.owner_user_id', $user], ['n.owner_user_id' => null]])->exists($this->db)) $allowed = false;
        }
        $level = 0; $quantity = 0;
        if ($allowed) {
            $level = (int)(new Query())->select('t.level')->from(['t' => 'actor_profession'])->innerJoin(['a' => 'game_actor'], '[[a.id]]=[[t.actor_id]]')
                ->innerJoin(['p' => 'profession'], '[[p.id]]=[[t.profession_id]]')->where(['a.user_id' => $user, 'a.kind' => 'player', 'p.code' => 'explorer', 'p.status' => 'published'])->scalar($this->db);
            if ($commonObjects) $level = max(1, $level);
            $capacity = (new CraftInventory(new CraftStorage($this->db)))->capacity($user)['active_slots'];
            $rows = (new Query())->select('i.*')->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')
                ->innerJoin(['d' => 'craft_item'], '[[d.id]]=[[i.item_id]]')->where(['i.user_id' => $user, 's.owner_user_id' => $user, 's.kind' => 'backpack', 's.status' => 'active', 'd.code' => ExplorationCatalog::ELIXIR, 'd.active' => 1])
                ->andWhere(['between', 'i.slot', 1, $capacity])->andWhere(['>', 'i.item_quantity', 0])->all($this->db);
            $reservations = new ProductionReservations($this->db);
            foreach ($rows as $row) $quantity += max(0, (int)$row['item_quantity'] - $reservations->quantity((int)$row['id']));
        }
        return ['allowed' => $allowed, 'level' => $level, 'max_level_per_node' => 3, 'elixir_quantity' => $quantity,
            'reason' => $allowed ? '' : 'Открывать ячейки может владелец действующего объекта.'];
    }
}
