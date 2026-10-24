<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftInventory, CraftStorage, StorageAccessPolicy};
use common\modules\world\support\GameError;
use yii\db\Query;

/** New batches use a local mini-storage; already accepted destinations retain their rights. */
final class ExtractionStorage
{
    public static function local(Node $node, bool $accepted = false): array
    {
        $query = (new Query())->select(['s.*', 'node_name' => 'n.name'])->from(['s' => 'craft_storage'])->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
            ->where(['s.owner_user_id' => $node->owner_user_id, 's.kind' => 'stockpile', 's.status' => 'active', 'n.status' => 'active'])->andWhere(['>', 'n.condition', 0]);
        $query->andWhere($accepted ? ['or', ['n.id' => $node->id], ['n.parent_id' => $node->id]] : ['n.parent_id' => $node->id, 'n.node_type' => 'CHEST']);
        return $query->orderBy(new \yii\db\Expression('ABS(n.position_x)+ABS(n.position_y), s.id'))->all(Node::getDb());
    }
    public static function choices(Node $node, int $user): array
    {
        if ((int)$node->owner_user_id !== $user) return [];
        $store = new CraftStorage(Node::getDb()); $backpack = (new CanonicalInventory($store))->backpack($user); $rows = [];
        if ($backpack) $rows[] = ['id' => (int)$backpack['id'], 'name' => 'Рюкзак', 'kind' => 'backpack', 'capacity' => (new CraftInventory($store))->capacity($user)['active_slots']];
        foreach (self::local($node) as $row) $rows[] = ['id' => (int)$row['id'], 'name' => trim($row['node_name']), 'kind' => 'stockpile', 'capacity' => (int)$row['capacity']];
        return $rows;
    }
    public static function ownerTarget(Node $node, int $user, ?int $target): int
    {
        $node->requireOwner($user); $choices = self::choices($node, $user);
        $target = $target ?? ($choices[0]['id'] ?? 0);
        if (!in_array($target, array_column($choices, 'id'), true)) throw new GameError('SOURCE_STORAGE_UNAVAILABLE', 'Выберите рюкзак или действующий мини-склад внутри этого объекта.', 422);
        (new StorageAccessPolicy(Node::getDb()))->storage($user, $target, true);
        return $target;
    }
    public static function fits(array $definition, array $target): bool
    {
        $db = Node::getDb(); $inventory = new CanonicalInventory(new CraftStorage($db)); $needed = 0;
        foreach ($definition['output'] as $code => $quantity) {
            $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->one($db);
            if (!$item) return false;
            $plan = $inventory->planCraft([], $target, [], [], $item, $quantity);
            if (!$plan['output_fits']) return false;
            foreach ($plan['grant'] as $grant) if ($grant['inventory_id'] === null) $needed++;
        }
        $occupied = (int)(new Query())->select('slot')->distinct()->from('craft_inventory')->where(['storage_id' => $target['id']])
            ->andWhere(['>', 'item_quantity', 0])->andWhere(['between', 'slot', 1, (int)$target['capacity']])->count('*', $db);
        return $occupied + $needed <= (int)$target['capacity'];
    }
    public static function nearest(Node $node, array $definition, bool $hired = false): array
    {
        $rows = self::local($node);
        if (!$rows) throw new GameError($hired ? 'EMPLOYER_STORAGE_REQUIRED' : 'SOURCE_STORAGE_UNAVAILABLE', 'Постройте действующий мини-склад внутри объекта.', 409);
        foreach ($rows as $row) {
            try { $target = (new StorageAccessPolicy(Node::getDb()))->storage((int)$node->owner_user_id, (int)$row['id'], true); }
            catch (\yii\web\HttpException $error) { continue; }
            if (self::fits($definition, $target)) return $target;
        }
        throw new GameError($hired ? 'EMPLOYER_STORAGE_FULL' : 'SOURCE_STORAGE_FULL', 'В мини-складах нет места для всей партии. Освободите место.', 409);
    }
}
