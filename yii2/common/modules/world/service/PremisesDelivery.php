<?php
namespace common\modules\world\service;

use common\modules\economy\service\EconomyHierarchy;
use common\services\game\CanonicalJson;
use yii\db\Connection;
use yii\db\Query;

/** Shared final delivery for a ready purchase and a completed construction. */
class PremisesDelivery
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    public function deliver(int $user, array $terms, int $transfer, string $operation, ?array $building = null): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Delivery requires a transaction.');
        $config = $terms['config']; $tree = new WorldTree($this->db); $code = 'premises-' . $operation;
        $siteId = (int)($terms['site_node_id'] ?? $terms['node_id']);
        $site = $tree->get($siteId);
        if ($site['node_type'] !== 'PLOT' || !(new Query())->from('world_plot')->where(['node_id' => $siteId, 'plot_kind' => 'campsite', 'allow_building' => 1])->exists($this->db)) throw new \LogicException('A purchased building must be placed directly on the starter campsite.');
        if (!$building) $building = $tree->create(['code' => $code, 'slug' => $code, 'node_type' => 'BUILDING', 'name' => $config['name'], 'parent_id' => $siteId, 'owner_user_id' => $user, 'visibility' => 'private'], ['operational_status' => 'active', 'template_revision_id' => $terms['template_revision_id']]);
        $roomName = $config['kind'] === 'house' ? 'Комната' : ($config['kind'] === 'workroom' ? 'Рабочее помещение' : 'Место под навесом');
        $room = $tree->create(['code' => $code . '-room', 'slug' => $config['kind'] === 'house' ? 'living-room' : 'workroom', 'node_type' => 'ROOM', 'name' => $roomName, 'parent_id' => (int)$building['id'], 'owner_user_id' => $user, 'visibility' => 'private'], ['area' => $config['area'], 'exposure_class' => $config['exposure_class']]);
        $roomId = (int)$room['id']; $buildingId = (int)$building['id'];
        $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'placement:node:' . $roomId, 'kind' => 'placement', 'owner_user_id' => $user, 'node_id' => $roomId, 'capacity' => $config['slots']])->execute(); $storage = (int)$this->db->getLastInsertID();
        for ($position = 1; $position <= $config['slots']; $position++) $this->db->createCommand()->insert('world_slot', ['storage_id' => $storage, 'code' => 'equipment-' . $position, 'position' => $position, 'slot_type' => 'equipment', 'size' => 1, 'exposure_class' => $config['exposure_class'], 'compatibility_json' => '{}'])->execute();
        (new EconomyHierarchy($this->db))->provision($roomId, $operation);
        $this->db->createCommand()->insert('world_premises_purchase', ['offer_id' => $terms['offer_id'], 'plot_id' => $siteId, 'building_id' => $buildingId, 'room_id' => $roomId, 'user_id' => $user, 'area' => $config['area'], 'transfer_id' => $transfer, 'operation_id' => $operation, 'terms_json' => CanonicalJson::encode($terms), 'created_at' => time()])->execute();
        (new WorldHousing($this->db, $this->flags))->initialize($roomId, $siteId, (int)$this->db->getLastInsertID(), $config, $operation);
        (new WorldEquipmentExpansion($this->db, $this->flags))->initialize($roomId, $storage, $terms['recipient_node_id'], $terms['template_revision_id'], $config, $operation);
        return ['building_id' => $buildingId, 'room_id' => $roomId, 'changed_node_ids' => [$terms['node_id'], $terms['recipient_node_id'], $buildingId, $roomId], 'changed_storage_ids' => [$storage]];
    }
}
