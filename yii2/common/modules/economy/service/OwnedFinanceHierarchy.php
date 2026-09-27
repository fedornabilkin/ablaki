<?php
namespace common\modules\economy\service;

use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldQuery;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Traverse current financial links, stopping at any inaccessible/non-owned node. No hidden counts. */
class OwnedFinanceHierarchy
{
    public const MAX_NODES = 200;
    public const MAX_DEPTH = 32;
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function resolve(int $user, int $root): array
    {
        $policy = new WorldAccessPolicy($user);
        $node = (new WorldQuery($this->db, $policy))->node($root);
        if (!$node['permissions']['storage']) throw new GameError('FINANCE_OWNER_REQUIRED', 'Финансовая иерархия доступна владельцу исходного объекта.', 403);
        $subject = (new Query())->select('id')->from('economy_subject')->where(['node_id' => $root])->scalar($this->db);
        $nodes = [$root => ['id' => $root, 'name' => $node['name'], 'type' => $node['type'], 'parent_node_id' => null, 'depth' => 0]];
        $frontier = $subject === false ? [] : [(int)$subject]; $subjects = $subject === false ? [] : [(int)$subject => $root]; $depth = 0;
        while ($frontier) {
            $query = (new Query())->select(['n.id', 'n.name', 'n.node_type', 'n.root_id', 'subject_id' => 's.id', 'h.parent_subject_id'])
                ->from(['h' => 'economy_parent_history'])
                ->innerJoin(['r' => 'economy_parent_rule'], '[[r.history_id]]=[[h.id]] AND [[r.subject_id]]=[[h.subject_id]] AND [[r.revision]]=[[h.revision]]')
                ->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[h.subject_id]]')
                ->innerJoin(['n' => 'world_node'], '[[n.id]]=[[s.node_id]]')
                ->where(['h.parent_subject_id' => $frontier, 'h.effective_to' => null, 'n.owner_user_id' => $user]);
            $rows = $policy->filter($query)->orderBy(['n.id' => SORT_ASC])->limit(self::MAX_NODES - count($nodes) + 1)->all($this->db);
            if (!$rows) break;
            if (++$depth > self::MAX_DEPTH || count($nodes) + count($rows) > self::MAX_NODES) throw new GameError('FINANCE_SCOPE_TOO_LARGE', 'Выберите меньшую финансовую ветвь для отчёта.', 422, ['max_nodes' => self::MAX_NODES, 'max_depth' => self::MAX_DEPTH]);
            $frontier = [];
            foreach ($rows as $row) {
                $id = (int)$row['id']; $sid = (int)$row['subject_id'];
                if (isset($nodes[$id]) || isset($subjects[$sid]) || (int)$row['root_id'] !== $node['root_id']) throw new GameError('FINANCE_HIERARCHY_INVALID', 'Финансовые связи требуют сверки.', 503);
                $nodes[$id] = ['id' => $id, 'name' => $row['name'], 'type' => $row['node_type'], 'parent_node_id' => $subjects[(int)$row['parent_subject_id']], 'depth' => $depth];
                $subjects[$sid] = $id; $frontier[] = $sid;
            }
        }
        return $nodes;
    }
}
