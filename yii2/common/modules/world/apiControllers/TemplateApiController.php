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
        Node::readable($parent, (int)Yii::$app->user->id);
        return $this->listing((new TemplateSearch())->search(Yii::$app->request->queryParams, $parent));
    }
}
