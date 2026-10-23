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
        $code = Yii::$app->request->post('action_code'); $target = Yii::$app->request->post('output_storage_id');
        if ($code !== null && (!is_string($code) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $code))) throw new \common\modules\world\support\GameError('INVALID_SOURCE_ACTION', 'Выберите действие источника.', 422);
        if ($target !== null) $target = $this->id($target);
        $payload = ['mining_node_id' => $node] + ($code === null ? [] : ['action_code' => $code]) + ($target === null ? [] : ['output_storage_id' => $target]);
        return $this->command('world.mining.' . $action, $payload, function ($operation) use ($node, $action, $code, $target) {
            $user = (int)Yii::$app->user->id;
            return $action === 'start' ? Mining::start($node, $user, $operation, $code, $target) : Mining::heartbeat($node, $user, $operation, $action === 'stop');
        });
    }
    public function actionStart($id): array { return $this->work($id, 'start'); }
    public function actionHeartbeat($id): array { return $this->work($id, 'heartbeat'); }
    public function actionStop($id): array { return $this->work($id, 'stop'); }
    public function actionSettings($id): array
    {
        $node = $this->id($id); $enabled = Yii::$app->request->post('enabled'); $reward = Yii::$app->request->post('reward_cr'); $storage = Yii::$app->request->post('storage_id');
        if (!is_bool($enabled) || !is_string($reward)) throw new \common\modules\world\support\GameError('INVALID_EMPLOYMENT', 'Проверьте допуск и стоимость партии.', 422);
        if ($storage !== null) $storage = $this->id($storage);
        return $this->command('world.mining.settings', ['mining_node_id' => $node, 'enabled' => $enabled, 'reward_cr' => $reward, 'storage_id' => $storage], static function () use ($node, $enabled, $reward, $storage) {
            return \common\modules\world\models\domain\HiredExtraction::configure(\common\modules\world\models\Node::requireOne($node), (int)Yii::$app->user->id, $enabled, $reward, $storage);
        });
    }
}
