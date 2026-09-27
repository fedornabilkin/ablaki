<?php
namespace common\modules\craft\service;

use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldQuery;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Append-only evidence. Journaling is in the same transaction as the physical unit change. */
class EquipmentWearHistory
{
    public const LABELS = ['elapsed' => 'Износ от времени', 'location' => 'Смена среды', 'work' => 'Использование', 'repair' => 'Ремонт'];
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function append(array $unit, ?array $before, array $after, string $kind, int $from, int $to, int $durability): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Wear history requires the inventory transaction.');
        // Older canonical installations remain readable/writable during the additive schema rollout.
        if (!$this->db->schema->getTableSchema('craft_equipment_wear_history')) return;
        if (!isset(self::LABELS[$kind]) || $to < $from) throw new \LogicException('Invalid wear interval.');
        $owner = (new Query())->select('user_id')->from('craft_inventory')->where(['id' => $unit['inventory_id']])->scalar($this->db);
        if (!$owner) throw new \LogicException('Wear history owner missing.');
        $this->db->createCommand()->insert('craft_equipment_wear_history', [
            'instance_id' => $unit['id'], 'owner_user_id' => $owner, 'kind' => $kind, 'started_at' => $from, 'ended_at' => $to,
            'durability_before' => (int)$unit['durability'], 'durability_after' => $durability,
            'remainder_before' => (int)($before['remainder'] ?? 0), 'remainder_after' => (int)$after['remainder'],
            'class_before' => $before['exposure_class'] ?? 'carried', 'class_after' => $after['exposure_class'],
            'daily_wear_before' => (int)($before['daily_wear'] ?? 0), 'daily_wear_after' => (int)$after['daily_wear'],
            'policy_version' => (int)$after['policy_version'], 'created_at' => time(),
        ])->execute();
    }
    public function state(int $user, int $id, int $page, string $kind, string $search, WorldFlags $flags): array
    {
        $flags->requireFlag('world_read'); $flags->requireFlag('storage_v2');
        if ($page < 1 || $page > 1000000 || ($kind !== '' && !isset(self::LABELS[$kind])) || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_WEAR_FILTER', 'Некорректный фильтр истории износа.', 422);
        $now = time();
        $unit = (new Query())->select(['e.*', 'i.storage_id', 'name' => 'item.name', 'x.exposure_class', 'x.daily_wear', 'x.settled_at', 'x.remainder', 'x.policy_version'])->from(['e' => 'craft_equipment_instance'])
            ->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->innerJoin(['item' => 'craft_item'], '[[item.id]]=[[e.item_id]]')
            ->leftJoin(['x' => 'craft_equipment_exposure'], '[[x.instance_id]]=[[e.id]]')
            ->where(['e.id' => $id, 'e.status' => 'active', 'i.user_id' => $user])->andWhere(['>', 'i.item_quantity', 0])->one($this->db);
        if (!$unit) throw new GameError('EQUIPMENT_UNAVAILABLE', 'Экземпляр не найден или недоступен.', 404);
        $storage = (new Query())->from('craft_storage')->where(['id' => $unit['storage_id'], 'owner_user_id' => $user, 'status' => 'active'])->one($this->db);
        if (!$storage) throw new GameError('EQUIPMENT_UNAVAILABLE', 'Хранилище недоступно.', 404);
        $returnNode = $storage['node_id'] === null ? null : (int)$storage['node_id'];
        if ($storage['kind'] === 'shelter') {
            $deployment = (new Query())->from('world_shelter_deployment')->where(['active_instance_id' => $id, 'node_id' => $storage['node_id']])->one($this->db);
            if (!$deployment) throw new GameError('EQUIPMENT_UNAVAILABLE', 'Размещение шалаша недоступно.', 404);
            $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node((int)$deployment['plot_id']);
            if (!$node['permissions']['storage']) throw new GameError('EQUIPMENT_UNAVAILABLE', 'Стоянка недоступна.', 404);
            $returnNode = (int)$node['id'];
        } else (new StorageAccessPolicy($this->db))->storage($user, (int)$storage['id']);
        $exposure = $unit['settled_at'] === null ? null : $unit;
        $projected = (new EquipmentExposure($this->db))->projectedWithExposure($unit, $exposure, $now);
        $query = (new Query())->from('craft_equipment_wear_history')->where(['instance_id' => $id, 'owner_user_id' => $user]);
        // A stable upper bound keeps count/items consistent when a worker appends during this GET.
        $last = (int)(clone $query)->max('id', $this->db); $query->andWhere(['<=', 'id', $last]);
        if ($kind !== '') $query->andWhere(['kind' => $kind]);
        if ($search !== '') {
            $kinds = [];
            foreach (self::LABELS as $code => $label) if (mb_stripos($label, $search, 0, 'UTF-8') !== false) $kinds[] = $code;
            $query->andWhere(['kind' => $kinds]);
        }
        $total = (int)(clone $query)->count('*', $this->db); $items = [];
        foreach ($query->orderBy(['id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
            unset($row['owner_user_id'], $row['instance_id']);
            foreach ($row as $key => $value) if (!in_array($key, ['kind', 'class_before', 'class_after'], true)) $row[$key] = (int)$value;
            $items[] = $row;
        }
        return ['instance_id' => $id, 'name' => $unit['name'], 'storage_id' => (int)$storage['id'], 'storage_kind' => $storage['kind'], 'return_node_id' => $returnNode,
            'durability' => (int)$projected['durability'], 'max_durability' => (int)$unit['max_durability'], 'exposure_class' => $projected['exposure_class'], 'daily_wear' => (int)($exposure['daily_wear'] ?? 0),
            'items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20], 'server_time' => $now];
    }
}
