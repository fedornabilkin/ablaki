<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\TemplateSearch;
use common\modules\world\models\Node;
use Yii;

class TemplateApiController extends CoreController
{
    public function actionIndex(): array
    {
        $parent = $this->id(Yii::$app->request->get('parent_id'));
        $node = Node::readable($parent, (int)Yii::$app->user->id);
        $provider = (new TemplateSearch())->search(Yii::$app->request->queryParams, $parent);
        $templates = $provider->getModels(); $codes = [];
        foreach ($templates as $template) {
            $defaults = $template->defaults();
            $codes += $template->materials() + ($defaults['build_tools'] ?? []) + ($defaults['build_stations'] ?? []);
        }
        $names = \common\modules\world\modules\craft\models\CraftItem::find()->select(['name', 'code'])->where(['code' => array_keys($codes)])->indexBy('code')->asArray()->all();
        $economy = \common\modules\world\models\domain\EconomyRuntime::current(Node::getDb());
        $owned = (int)$node->owner_user_id === (int)Yii::$app->user->id && $node->status === 'active';
        $availability = $owned ? \common\modules\world\models\BuildRequirements::catalogAvailability((int)Yii::$app->user->id, $parent, $templates) : [];
        $items = []; $rate = \common\modules\world\models\WorldTime::rate();
        foreach ($templates as $template) {
            $defaults = $template->defaults(); $parts = [];
            foreach ($template->materials() + ($defaults['build_tools'] ?? []) + ($defaults['build_stations'] ?? []) as $code => $quantity) $parts[] = ($names[$code]['name'] ?? $code) . ': ' . $quantity;
            $item = $template->getAttributes() + ['economy' => \common\modules\world\models\domain\EconomyConstruction::catalogTerms($template, $economy),
                'world_time_rate' => $rate, 'world_build_seconds' => (int)$template->build_seconds * $rate, 'material_summary' => implode(', ', $parts)];
            $item += $owned ? $availability[(int)$template->id]
                : ['available' => false, 'unavailable_reason' => $node->status !== 'active' ? 'Сначала завершите строительство.' : 'Требуются права владельца.'];
            $items[] = $item;
        }
        return ['items' => $items, '_meta' => ['totalCount' => $provider->getTotalCount(), 'pageCount' => $provider->pagination->pageCount,
            'currentPage' => $provider->pagination->page + 1, 'perPage' => $provider->pagination->pageSize]];
    }
}
