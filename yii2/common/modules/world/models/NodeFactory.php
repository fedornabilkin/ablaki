<?php
namespace common\modules\world\models;

use common\modules\world\models\domain\WorldLayout;
use common\modules\world\models\domain\WorldTree;
use yii\db\Query;
use yii\helpers\Json;

final class NodeFactory
{
    public static function create(NodeTemplate $template, ?Node $parent, ?int $owner, string $name, string $code, ?array $cell = null, string $status = 'active'): Node
    {
        $db = Node::getDb();
        if (!$db->getTransaction()) throw new \LogicException('Object creation requires a transaction.');
        $node = new Node(); $node->loadDefaultValues();
        $node->setAttributes(['name' => $name, 'label' => $name, 'code' => $code, 'slug' => $code,
            'parent_id' => $parent ? $parent->id : null, 'template_id' => $template->id, 'owner_user_id' => $owner,
            'status' => $status, 'visibility' => 'public']);
        self::initialize($node, $template);
        $node->max_condition = BuildStrength::maximum($template->worldWorkSeconds());
        $node->condition = $status === 'constructing' ? 0 : $node->max_condition;
        if ($parent) {
            $position = $cell ?? (new WorldTree($db))->nextPosition((int)$parent->id);
            $node->position_x = $position['x']; $node->position_y = $position['y'];
        }
        if ($template->kind === 'BED') {
            $node->garden_node_id = $parent->id;
            $node->ordinal = 1 + (int)Node::find()->where(['parent_id' => $parent->id])->max('ordinal');
        }
        if ($template->kind === 'BUILDING') {
            $node->operational_status = $status; $node->level = 1;
        }
        $node->persist();
        if ($parent) {
            $where = ['parent_id' => $parent->id, 'x' => $node->position_x, 'y' => $node->position_y];
            if (!(new Query())->from('world_map_cell')->where($where)->exists($db)) $db->createCommand()->insert('world_map_cell', $where + [
                'state' => 'open', 'price' => '0.0000', 'operation_id' => null, 'created_at' => time(), 'updated_at' => time(),
            ])->execute();
        }
        if ($node->status === 'active') (new \common\modules\world\models\domain\PlacementProvisioner($db))->openObjectMap($node);
        return $node;
    }
    public static function initialize(Node $node, NodeTemplate $template): void
    {
        $defaults = $template->defaults();
        $allowed = ['climate', 'settlement_kind', 'population', 'plot_limit', 'building_kind', 'level', 'condition', 'max_condition',
            'area', 'exposure_class', 'plot_kind', 'fertility', 'allow_building', 'unlocked'];
        $node->setAttributes(array_intersect_key($defaults, array_flip($allowed)), false);
        $node->settings_json = Json::encode((object)($defaults + ['strength_version' => 1]));
        // All new objects use one map policy; templates may configure dimensions.
        $width = (int)($defaults['map_width'] ?? 5); $height = (int)($defaults['map_height'] ?? 5);
        $node->setAttributes(['map_width' => $width, 'map_height' => $height,
            'map_origin_x' => -intdiv($width, 2), 'map_origin_y' => -intdiv($height, 2)], false);
    }
}
