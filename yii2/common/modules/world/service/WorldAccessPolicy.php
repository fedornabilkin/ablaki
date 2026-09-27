<?php
namespace common\modules\world\service;

use common\services\game\GameError;
use yii\db\Expression;
use yii\db\Query;

class WorldAccessPolicy
{
    private $userId;
    private $admin;
    public function __construct(int $userId, bool $admin = false) { $this->userId = $userId; $this->admin = $admin; }
    public function owns(array $node): bool { return $this->admin || (int)$node['owner_user_id'] === $this->userId; }
    public function ownsItems(array $node): bool { return (int)$node['owner_user_id'] === $this->userId; }
    public function isAdmin(): bool { return $this->admin; }
    public function filter(Query $query, string $alias = 'n'): Query
    {
        if ($this->admin) return $query;
        $hidden = (new Query())->select(new Expression('1'))->from(['path' => 'world_node_closure'])
            ->innerJoin(['ancestor' => 'world_node'], '[[ancestor.id]]=[[path.ancestor_id]]')
            ->where(new Expression('[[path.descendant_id]]=[[' . $alias . '.id]]'))
            ->andWhere(['or', ['ancestor.status' => 'archived'], ['and', ['<>', 'ancestor.visibility', 'public'], ['or', ['ancestor.owner_user_id' => null], ['<>', 'ancestor.owner_user_id', $this->userId]]]]);
        return $query->andWhere(['<>', $alias . '.status', 'archived'])
            ->andWhere(['or', [$alias . '.visibility' => 'public'], [$alias . '.owner_user_id' => $this->userId]])
            ->andWhere(['not exists', $hidden]);
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
