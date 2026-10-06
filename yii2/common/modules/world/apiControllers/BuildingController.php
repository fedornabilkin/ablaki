<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class BuildingController extends LegacyController
{
    private function repairContractService(): \common\modules\world\models\domain\WorldRepairContracts
    {
        return new \common\modules\world\models\domain\WorldRepairContracts(Yii::$app->db, $this->flags());
    }
    public function actionRepairContracts($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->repairContractService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function repairContractCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректные условия договора.', 422);
        $input = ['node_id' => $this->id($id), 'offer_id' => $this->id($body['offer_id'] ?? null)]; $user = (int)Yii::$app->user->id; $service = $this->repairContractService();
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionRepairContractPreview($id): array { return $this->repairContractCommand($id, true); }
    public function actionRepairContractExecute($id): array { return $this->repairContractCommand($id, false); }
    private function buildingRepairService(): \common\modules\world\models\domain\WorldBuildingRepair
    {
        return new \common\modules\world\models\domain\WorldBuildingRepair(Yii::$app->db, $this->flags());
    }
    public function actionBuildingRepair($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->buildingRepairService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function buildingRepairCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->buildingRepairService();
        if ($preview) return $service->preview($user, $input);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionBuildingRepairPreview($id): array { return $this->buildingRepairCommand($id, true); }
    public function actionBuildingRepairExecute($id): array { return $this->buildingRepairCommand($id, false); }
    private function buildingOperationService(): \common\modules\world\models\domain\WorldBuildingOperation
    {
        return new \common\modules\world\models\domain\WorldBuildingOperation(Yii::$app->db, $this->flags());
    }
    public function actionBuildingOperation($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->buildingOperationService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function buildingOperationCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->buildingOperationService();
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionBuildingOperationPreview($id, string $operation): array { return $this->buildingOperationCommand($id, $operation, true); }
    public function actionBuildingOperationExecute($id, string $operation): array { return $this->buildingOperationCommand($id, $operation, false); }
}
