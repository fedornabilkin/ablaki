<?php
use common\modules\world\models\Node;
use common\modules\world\models\NodeFactory;
use common\modules\world\models\NodeTemplate;
use common\modules\world\models\domain\PlacementProvisioner;
use common\modules\world\models\domain\WorldTree;
use common\modules\world\models\domain\WorldMapGeometry;
use common\modules\world\models\migration\SimpleWorldSeed;
use common\modules\world\modules\craft\models\domain\CraftSeed;
use common\modules\world\support\Locks;
use common\modules\world\support\GameError;
use common\modules\world\support\WorldDomainMigration;
use yii\db\Query;
use yii\helpers\Json;

/** Forward-only content upgrade; keeps inventory identities and financial history. */
class m261008_120000_world_mvp_facilities extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_furnishing_rule', ['exposures_json' => $this->text()->notNull(), 'lodging' => $this->smallInteger()->notNull()->defaultValue(0)], ['item_id' => ['craft_item']], [['item_id']]);
        $this->db->transaction(function () {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'world.mvp.facilities', 'created_at' => time()])->execute();
            CraftSeed::run($this->db); (new SimpleWorldSeed($this->db))->run();
            foreach (['classic-bed' => [['covered', 'indoor'], 1], 'classic-campfire' => [['outdoor'], 0]] as $code => $rule) {
                $item = (new Query())->select('id')->from('craft_item')->where(['code' => $code])->scalar($this->db);
                if ($item && !(new Query())->from('world_furnishing_rule')->where(['item_id' => $item])->exists($this->db)) $this->db->createCommand()->insert('world_furnishing_rule', ['item_id' => $item, 'exposures_json' => Json::encode($rule[0]), 'lodging' => $rule[1]])->execute();
            }
            // Update the shipped house only when its original recipe is still present.
            $house = NodeTemplate::findOne(['code' => 'house']);
            if ($house && $house->materials() === ['classic-plank' => 12, 'classic-stone' => 8]) $this->db->createCommand()->update('world_template', ['materials_json' => Json::encode(['classic-plank' => 100, 'classic-stone' => 80, 'classic-rope' => 30]), 'build_seconds' => 1800], ['id' => $house->id])->execute();
            foreach (['starter-shelter' => 'covered', 'house' => 'indoor'] as $code => $exposure) {
                $template = NodeTemplate::findOne(['code' => $code]);
                if (!$template) continue;
                $defaults = $template->defaults();
                if (!isset($defaults['exposure_class'])) $this->db->createCommand()->update('world_template', ['defaults_json' => Json::encode($defaults + ['exposure_class' => $exposure])], ['id' => $template->id])->execute();
                $this->db->createCommand()->update('world_node', ['exposure_class' => $exposure], ['template_id' => $template->id, 'exposure_class' => null])->execute();
            }
            // Every parent level uses the same capacity/sales settings and construction flow.
            for ($level = 1; $level <= 6; $level++) {
                $parents = NodeTemplate::find()->where(['hierarchy_level' => $level, 'enabled' => 1])->all();
                if (!$parents) continue;
                $code = 'mini-storage-level-' . ($level + 1);
                $template = NodeTemplate::findOne(['code' => $code]);
                if (!$template) {
                    $template = new NodeTemplate(); $template->setAttributes(['code' => $code, 'name' => 'Мини-склад', 'kind' => 'CHEST', 'hierarchy_level' => $level + 1, 'build_seconds' => 60, 'enabled' => 1, 'player_buildable' => 1,
                        'materials_json' => Json::encode(['classic-plank' => 4, 'classic-rope' => 1]), 'defaults_json' => Json::encode(['storage_capacity' => 5, 'allow_sales' => true])], false);
                    $template->parentIds = array_map(static function ($parent) { return (int)$parent->id; }, $parents); $template->persist();
                }
            }
            // Make former implicit warehouses visible objects; never discard their contents.
            $storages = (new Query())->select('s.*')->from(['s' => 'craft_storage'])->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
                ->where(['s.kind' => ['harvest', 'stockpile'], 's.status' => 'active'])->andWhere(['<>', 'n.node_type', 'CHEST'])->all($this->db);
            foreach ($storages as $storage) {
                $parent = Node::requireOne($storage['node_id']);
                if (isset((Json::decode($parent->settings_json ?: '{}'))['storage_capacity'])) continue;
                if ($parent->status !== 'active' || !$parent->owner_user_id || (int)$parent->hierarchy_level >= 7) continue;
                $template = NodeTemplate::requireOne(['code' => 'mini-storage-level-' . ((int)$parent->hierarchy_level + 1)]);
                try {
                    $cell = (new WorldTree($this->db))->nextPosition((int)$parent->id);
                } catch (GameError $error) {
                    if ($error->reason !== 'MAP_FULL') throw $error;
                    // This warehouse already exists implicitly. Add room for its visible
                    // representation without moving equipment or consuming purchased cells.
                    $x = (int)$parent->map_origin_x + (int)$parent->map_width;
                    foreach ((new PlacementProvisioner($this->db))->equipmentCells((int)$parent->id) as $occupied) $x = max($x, $occupied['x'] + 1);
                    foreach (Node::find()->where(['parent_id' => $parent->id])->andWhere(['<>', 'status', 'archived'])->all() as $child)
                        foreach (WorldMapGeometry::cells($child->footprint_json, (int)$child->position_x, (int)$child->position_y) as $occupied) $x = max($x, $occupied['x'] + 1);
                    $cell = ['x' => $x, 'y' => (int)$parent->map_origin_y];
                    $this->db->createCommand()->update('world_node', ['map_width' => $x - (int)$parent->map_origin_x + 1, 'revision' => (int)$parent->revision + 1], ['id' => $parent->id])->execute();
                    $parent->refresh();
                }
                $node = NodeFactory::create($template, $parent, (int)$parent->owner_user_id, 'Мини-склад', 'mvp-stockpile-' . $storage['id'], $cell, 'constructing');
                $this->db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $node->max_condition], ['id' => $node->id])->execute(); $node->refresh();
                $this->db->createCommand()->update('craft_storage', ['kind' => 'stockpile', 'node_id' => $node->id], ['id' => $storage['id']])->execute();
                (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($this->db))->provision((int)$node->id, $operation);
                (new PlacementProvisioner($this->db))->openObjectMap($node);
            }
            foreach (Node::find()->where(['status' => 'active'])->andWhere(['not', ['owner_user_id' => null]])->all() as $node) {
                (new PlacementProvisioner($this->db))->interior($node);
                \common\modules\world\models\domain\FurnishingPlacement::synchronize($this->db, [(int)$node->id], $operation);
            }
        });
    }
}
