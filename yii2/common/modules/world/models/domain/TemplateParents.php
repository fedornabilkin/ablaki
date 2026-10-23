<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Default catalog edges; custom administrator edges and existing nodes are retained. */
final class TemplateParents
{
    public static function seed(Connection $db): void
    {
        $templates = (new Query())->from('world_template')->indexBy('code')->all($db);
        $parents = [];
        foreach (require dirname(__DIR__, 2) . '/data/simple-templates.php' as $code => $spec) $parents[$code] = $spec[3];
        foreach (['forest-plot', 'stone-plot', 'clay-plot', 'sand-plot', 'meadow-plot', 'water-source', 'campfire-place', 'cookfire', 'trap', 'workshop-building', 'storage-building', 'small-greenhouse', 'field-kitchen'] as $code) $parents[$code] = ['starter-site', 'estate'];
        $parents['garden-bed'][] = 'small-greenhouse';
        foreach ($templates as $code => $template) if (preg_match('/^mini-storage-level-([2-7])$/D', $code, $match)) {
            foreach ($templates as $parentCode => $parent) if ((int)$parent['hierarchy_level'] === (int)$match[1] - 1) $parents[$code][] = $parentCode;
        }
        foreach ($parents as $code => $codes) if (isset($templates[$code])) foreach (array_unique($codes) as $parent) if (isset($templates[$parent])) {
            $edge = ['template_id' => $templates[$code]['id'], 'parent_template_id' => $templates[$parent]['id']];
            if (!(new Query())->from('world_template_parent')->where($edge)->exists($db)) $db->createCommand()->insert('world_template_parent', $edge)->execute();
        }
    }
}
