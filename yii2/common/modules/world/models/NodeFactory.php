<?php
namespace common\modules\world\models;

use common\modules\world\models\domain\WorldLayout;
use common\modules\world\models\domain\WorldTree;
use yii\db\Query;
use yii\helpers\Json;

final class NodeFactory
{
    public static function create(NodeTemplate $template, ?Node $parent, ?int $owner, string $name, string $code): Node
    {
        $db = Node::getDb();
        if (!$db->getTransaction()) throw new \LogicException('Object creation requires a transaction.');
        $node = new Node(); $node->loadDefaultValues();
        $node->setAttributes(['name' => $name, 'label' => $name, 'code' => $code, 'slug' => $code,
            'parent_id' => $parent ? $parent->id : null, 'template_id' => $template->id, 'owner_user_id' => $owner,
            'status' => (int)$template->hierarchy_level > 3 ? 'constructing' : 'active', 'visibility' => 'public']);
        self::initialize($node, $template);
        if ($parent) {
            $position = (new WorldTree($db))->nextPosition((int)$parent->id);
            $node->position_x = $position['x']; $node->position_y = $position['y'];
        }
        if ($template->kind === 'BED') {
            $node->garden_node_id = $parent->id;
            $node->ordinal = 1 + (int)Node::find()->where(['parent_id' => $parent->id])->max('ordinal');
        }
        if ($template->kind === 'BUILDING') {
            $node->operational_status = 'constructing'; $node->condition = 100; $node->max_condition = 100; $node->level = 1;
        }
        $node->persist();
        return $node;
    }
    public static function initialize(Node $node, NodeTemplate $template): void
    {
        $defaults = $template->defaults();
        $allowed = ['climate', 'settlement_kind', 'population', 'plot_limit', 'building_kind', 'level', 'condition', 'max_condition',
            'area', 'exposure_class', 'plot_kind', 'fertility', 'allow_building', 'unlocked'];
        $node->setAttributes(array_intersect_key($defaults, array_flip($allowed)), false);
        $node->settings_json = Json::encode((object)$defaults);
        $node->setAttributes(WorldLayout::defaults($template->kind, $defaults), false);
    }
}
