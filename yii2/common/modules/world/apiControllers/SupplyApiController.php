<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\Supplies;
use Yii;

class SupplyApiController extends CoreController
{
    public function actionCreate($id): array
    {
        $node = $this->id($id);
        return $this->command('world.gather', ['node_id' => $node], function ($operation) use ($node) {
            return Supplies::gather($node, (int)Yii::$app->user->id, $operation);
        });
    }
}
