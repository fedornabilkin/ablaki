<?php
use common\modules\world\models\{Build, BuildStrength, Node};
use common\modules\world\models\domain\{WorldFlags, WorldNights};
use common\modules\world\models\migration\SimpleWorldSeed;
use common\modules\world\support\{Locks, WorldDomainMigration};
use yii\helpers\Json;

/** Add extraction and forward-only strength conversion without changing accepted work durations. */
class m261008_160000_world_construction_mining extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_mining_state', ['seconds_per_unit' => $this->integer()->notNull(), 'worked_seconds' => $this->bigInteger()->notNull()->defaultValue(0), 'produced_quantity' => $this->bigInteger()->notNull()->defaultValue(0)], ['node_id' => ['world_node'], 'item_id' => ['craft_item']], [], [], ['node_id']);
        $this->domain('world_mining_worker', ['accounted_at' => $this->integer()->notNull(), 'last_seen_at' => $this->integer()->notNull()], ['user_id' => ['user'], 'node_id' => ['world_node']], [], [['node_id']], ['user_id']);
        $this->db->transaction(function () {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            (new SimpleWorldSeed($this->db))->run();
            $mineTemplate = \common\modules\world\models\NodeTemplate::requireOne(['code' => 'mine']);
            $defaults = $mineTemplate->defaults();
            if (!isset($defaults['production_enabled'])) $this->db->createCommand()->update('world_template', ['defaults_json' => Json::encode((object)($defaults + ['production_enabled' => true]))], ['id' => $mineTemplate->id])->execute();
            foreach (Node::find()->with('template')->all() as $node) {
                if (!$node->template) continue;
                $settings = Json::decode($node->settings_json ?: '{}');
                if (!isset($settings['strength_version'])) {
                    $build = Build::find()->where(['node_id' => $node->id])->orderBy(['id' => SORT_DESC])->one();
                    $maximum = BuildStrength::maximum($build ? (int)$build->required_seconds : (int)$node->template->build_seconds);
                    $condition = $node->status === 'constructing' ? BuildStrength::current($build ? (int)$build->worked_seconds : 0, $build ? (int)$build->required_seconds : 1, $maximum)
                        : ($node->max_condition ? (int)floor((int)$node->condition * $maximum / (int)$node->max_condition) : $maximum);
                    $settings['strength_version'] = 1;
                    $this->db->createCommand()->update('world_node', ['condition' => min($condition, $maximum), 'max_condition' => $maximum], ['id' => $node->id])->execute();
                }
                if ($node->template->code === 'mine' && !isset($settings['production_enabled'])) $settings['production_enabled'] = true;
                $this->db->createCommand()->update('world_node', ['settings_json' => Json::encode((object)$settings)], ['id' => $node->id])->execute();
            }
            $this->db->createCommand()->update('world_crop_cycle', ['water_due_at' => null], ['and', ['closed_at' => null], ['>=', 'water_due_at', new \yii\db\Expression('[[ready_at]]')]])->execute();
            (new WorldNights($this->db, new WorldFlags($this->db, Yii::$app->getModule('world'))))->discover();
        });
    }
}
