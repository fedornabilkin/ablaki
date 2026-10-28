<?php
use common\modules\world\support\WorldDomainMigration;

class m261023_200000_world_template_context extends WorldDomainMigration
{
    public function safeUp()
    {
        \common\modules\world\models\domain\TemplateParents::seed($this->db);
        // These two facilities are outdoors. Preserve explicit per-node exposure overrides.
        foreach ((new \yii\db\Query())->from('world_template')->where(['code' => ['campfire-place', 'cookfire']])->all($this->db) as $template) {
            $defaults = json_decode($template['defaults_json'], true) ?: [];
            if (!isset($defaults['exposure_class'])) {
                $defaults['exposure_class'] = 'outdoor';
                $this->db->createCommand()->update('world_template', ['defaults_json' => json_encode((object)$defaults)], ['id' => $template['id']])->execute();
            }
            if ($defaults['exposure_class'] !== 'outdoor') continue;
            foreach ((new \yii\db\Query())->from('world_node')->where(['template_id' => $template['id']])->all($this->db) as $node) {
                $settings = json_decode($node['settings_json'], true) ?: [];
                if (isset($settings['exposure_class'])) continue;
                $settings['exposure_class'] = 'outdoor';
                $this->db->createCommand()->update('world_node', ['exposure_class' => 'outdoor', 'settings_json' => json_encode((object)$settings)], ['id' => $node['id']])->execute();
                $storages = (new \yii\db\Query())->select('id')->from('craft_storage')->where(['node_id' => $node['id'], 'kind' => 'placement']);
                $this->db->createCommand()->update('world_slot', ['exposure_class' => 'outdoor'], ['storage_id' => $storages])->execute();
            }
        }
    }
}
