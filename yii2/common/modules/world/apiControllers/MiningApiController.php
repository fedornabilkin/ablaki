<?php
namespace common\modules\world\apiControllers;
use common\modules\world\models\Mining;
use Yii;

class MiningApiController extends CoreController
{
    public function actionView($id): array { return Mining::state($this->id($id), (int)Yii::$app->user->id); }
    private function work($id, string $action): array
    {
        $node = $this->id($id);
        return $this->command('world.mining.' . $action, ['mining_node_id' => $node], function ($operation) use ($node, $action) {
            $user = (int)Yii::$app->user->id;
            return $action === 'start' ? Mining::start($node, $user, $operation) : Mining::heartbeat($node, $user, $operation, $action === 'stop');
        });
    }
    public function actionStart($id): array { return $this->work($id, 'start'); }
    public function actionHeartbeat($id): array { return $this->work($id, 'heartbeat'); }
    public function actionStop($id): array { return $this->work($id, 'stop'); }
}
