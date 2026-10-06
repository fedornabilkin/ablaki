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
        if ($this->admin) return $query;
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
