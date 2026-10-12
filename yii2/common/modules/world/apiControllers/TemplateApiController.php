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
        $result = $this->listing((new TemplateSearch())->search(Yii::$app->request->queryParams, $parent));
        foreach ($result['items'] as &$item) {
            $template = \common\modules\world\models\NodeTemplate::requireOne($item['id']);
            $item += (int)$node->owner_user_id === (int)Yii::$app->user->id && $node->status === 'active'
                ? \common\modules\world\models\BuildRequirements::availability((int)Yii::$app->user->id, $parent, $template)
                : ['available' => false, 'unavailable_reason' => $node->status !== 'active' ? 'Сначала завершите строительство.' : 'Требуются права владельца.'];
        }
        return $result;
    }
}
