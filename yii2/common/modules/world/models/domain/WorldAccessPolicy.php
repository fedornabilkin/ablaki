<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\Query;

class WorldAccessPolicy
{
    private $userId;
    private $admin;
    public function __construct(int $userId, bool $admin = false) { $this->userId = $userId; $this->admin = $admin; }
    public function userId(): int { return $this->userId; }
    public function owns(array $node): bool { return $this->admin || (int)$node['owner_user_id'] === $this->userId; }
    public function ownsItems(array $node): bool { return (int)$node['owner_user_id'] === $this->userId; }
    public function isAdmin(): bool { return $this->admin; }
    public function filter(Query $query, string $alias = 'n'): Query
    {
        if ($this->admin) {
            // Administrative permissions reveal private objects, not retired map positions.
            $archived = (new Query())->select('archived_path.ancestor_id')->from(['archived_path' => 'world_node_closure'])
                ->innerJoin(['archived_node' => 'world_node'], '[[archived_node.id]]=[[archived_path.ancestor_id]]')
                ->where('[[archived_path.descendant_id]]=[[' . $alias . '.id]]')->andWhere(['archived_node.status' => 'archived']);
            return $query->andWhere(['<>', $alias . '.status', 'archived'])->andWhere(['not exists', $archived]);
        }
        if (isset(\common\modules\world\models\Node::getTableSchema()->columns['hierarchy_level']))
            return $query->andWhere([$alias . '.id' => \common\modules\world\models\Node::visibleTo($this->userId)->select('id')]);
        // World maps show every active object, regardless of its owner. Management
        // permissions and private details remain restricted by the owner check.
        return $query->andWhere(['<>', $alias . '.status', 'archived']);
    }
    public function requireManage(array $node): void
    {
        if (!$this->owns($node)) throw new GameError('FORBIDDEN', 'Нет прав на управление объектом.', 403);
    }
    public function requireAdmin(): void
    {
        if (!$this->admin) throw new GameError('FORBIDDEN', 'Требуются права управления миром.', 403);
    }
}
