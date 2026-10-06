<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\EventSearch;
use Yii;

class EventApiController extends CoreController
{
    public function actionIndex(): array
    {
        return $this->listing((new EventSearch())->search(Yii::$app->request->queryParams, $this->id(Yii::$app->request->get('node_id')), (int)Yii::$app->user->id));
    }
}
