<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class GardenController extends LegacyController
{
    private function gardenService(): \common\modules\world\models\domain\WorldGarden
    {
        return new \common\modules\world\models\domain\WorldGarden(Yii::$app->db, $this->flags(), $this->policy());
    }
    public function actionGarden($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->gardenService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionEquipmentExpansion($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\models\domain\WorldEquipmentExpansion(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function equipmentExpansionCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = new \common\modules\world\models\domain\WorldEquipmentExpansion(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        $input = ['node_id' => $this->id($id)] + $service->input($body);
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionEquipmentExpandPreview($id): array { return $this->equipmentExpansionCommand($id, true); }
    public function actionEquipmentExpand($id): array { return $this->equipmentExpansionCommand($id, false); }
    private function gardenCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие с огородом.', 422);
        $service = $this->gardenService(); $user = (int)Yii::$app->user->id;
        $input = ['node_id' => $this->id($id)] + $service->input($body, $operation);
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionGardenPreview($id, string $operation): array { return $this->gardenCommand($id, $operation, true); }
    public function actionGardenExecute($id, string $operation): array { return $this->gardenCommand($id, $operation, false); }
}
