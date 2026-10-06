<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\Node;
use common\modules\world\models\NodeSearch;
use Yii;

class ObjectApiController extends CoreController
{
    public function actionIndex(): array { return $this->listing((new NodeSearch())->search(Yii::$app->request->queryParams, (int)Yii::$app->user->id)); }
    public function actionView($id): array { return Node::readable($this->id($id), (int)Yii::$app->user->id)->toArray(); }
}
