<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class HarvestController extends LegacyController
{
    private function harvestService(): \common\modules\world\models\domain\GardenHarvest { return new \common\modules\world\models\domain\GardenHarvest(Yii::$app->db, $this->flags()); }
    public function actionHarvest($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->harvestService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function harvestCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->harvestService(); $input = $service->input($body, $operation); $user = (int)Yii::$app->user->id; $node = $this->id($id);
        if ($preview) return $service->preview($user, $node, $operation, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $node, $operation, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionHarvestPreview($id, string $operation): array { return $this->harvestCommand($id, $operation, true); }
    public function actionHarvestExecute($id, string $operation): array { return $this->harvestCommand($id, $operation, false); }
}
