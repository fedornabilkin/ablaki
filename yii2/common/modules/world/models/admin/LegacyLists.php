<?php
namespace common\modules\world\models\admin;

use yii\data\ActiveDataProvider;
use yii\db\Query;

/** Read models for the retained crop, equipment and warehouse administration. */
final class LegacyLists
{
    public static function templateConfig(int $id): string { return (string)(new Query())->select('config_json')->from('world_template_revision')->where(['id' => $id])->scalar(); }
    public static function settlements(int $user): array
    {
        return (new Query())->select(['name', 'id'])->from('world_node')->where(['node_type' => 'SETTLEMENT', 'status' => 'active', 'visibility' => 'public'])
            ->andWhere(['or', ['owner_user_id' => null], ['owner_user_id' => $user]])->orderBy(['name' => SORT_ASC])->indexBy('id')->column();
    }
    public static function nodes(string $type, string $q, string $status, ?int $parent, ?int $owner): ActiveDataProvider
    {
        $query = (new Query())->from('world_node')->where(['node_type' => $type])->andFilterWhere(['status' => $status, 'parent_id' => $parent, 'owner_user_id' => $owner])
            ->andFilterWhere(['or', ['like', 'name', trim($q)], ['like', 'code', trim($q)]]);
        return new ActiveDataProvider(['query' => $query, 'key' => 'id', 'pagination' => ['pageSize' => 20],
            'sort' => ['attributes' => ['id', 'name', 'updated_at'], 'defaultOrder' => ['id' => SORT_DESC]]]);
    }
    public static function children(int $parent): ActiveDataProvider
    {
        return new ActiveDataProvider(['query' => (new Query())->from('world_node')->where(['parent_id' => $parent])->orderBy(['id' => SORT_DESC]),
            'key' => 'id', 'pagination' => ['pageSize' => 20, 'pageParam' => 'children-page'], 'sort' => false]);
    }
    public static function crop(int $id): ?array
    {
        return (new Query())->select(['r.*', 'c.code', 'c.name'])->from(['r' => 'world_crop_revision'])
            ->innerJoin(['c' => 'world_crop'], 'c.id=r.crop_id')->where(['c.id' => $id])->orderBy(['r.version' => SORT_DESC])->one() ?: null;
    }
    public static function crops(string $q): ActiveDataProvider
    {
        $query = (new Query())->from('world_crop')->andFilterWhere(['or', ['like', 'name', $q], ['like', 'code', $q]]);
        return new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 20], 'sort' => ['attributes' => ['id', 'name'], 'defaultOrder' => ['id' => SORT_DESC]]]);
    }
    public static function items(): array
    {
        return (new Query())->select(['name', 'id'])->from('craft_item')->where(['active' => 1, 'storage_kind' => 'none'])->orderBy('name')->indexBy('id')->column();
    }
    public static function warehouses(): Query
    {
        return (new Query())->select(['s.id', 's.node_id', 's.owner_user_id', 's.capacity', 's.status', 'n.name', 'p.initial_capacity', 'p.max_capacity', 'p.base_price', 'p.revision'])
            ->from(['s' => 'craft_storage'])->innerJoin(['p' => 'world_warehouse_policy'], 'p.storage_id=s.id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id');
    }
    public static function warehouse(int $id): ?array { return self::warehouses()->where(['s.id' => $id])->one() ?: null; }
    public static function warehouseList(string $q): ActiveDataProvider
    {
        return new ActiveDataProvider(['query' => self::warehouses()->andFilterWhere(['like', 'n.name', $q]), 'key' => 'id',
            'pagination' => ['pageSize' => 20], 'sort' => ['attributes' => ['id', 'name', 'capacity']]]);
    }
    public static function slots(?int $node, string $q): ActiveDataProvider
    {
        $query = (new Query())->select(['s.*', 'node_id' => 'n.id', 'room_name' => 'n.name', 'owner_user_id' => 't.owner_user_id'])
            ->from(['s' => 'world_slot'])->innerJoin(['t' => 'craft_storage'], 't.id=s.storage_id')->innerJoin(['n' => 'world_node'], 'n.id=t.node_id')
            ->andFilterWhere(['n.id' => $node])->andFilterWhere(['or', ['like', 's.code', trim($q)], ['like', 'n.name', trim($q)]]);
        return new ActiveDataProvider(['query' => $query->orderBy(['t.id' => SORT_DESC, 's.position' => SORT_ASC]),
            'key' => static function (array $row): string { return $row['storage_id'] . ':' . $row['position']; },
            'pagination' => ['pageSize' => 20], 'sort' => false]);
    }
}
