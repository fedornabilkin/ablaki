<?php
namespace common\modules\world\apiControllers;

use common\modules\world\modules\craft\models\domain\WorldStorage;
use common\modules\world\support\GameError;
use Yii;

class StorageController extends LegacyController
{
    public function actionPlacementOptions($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\modules\craft\models\domain\WorldStorage(Yii::$app->db, $this->flags()))->placementOptions((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionStorages(): array
    {
        $node = Yii::$app->request->get('node_id');
        return (new WorldStorage(Yii::$app->db, $this->flags()))->list((int)Yii::$app->user->id, $node === null ? null : $this->id($node));
    }
    public function actionStorage($id): array
    {
        return (new WorldStorage(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)));
    }
    private function storageTransfer(bool $preview): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $payload = [];
        foreach (['inventory_id', 'source_storage_id', 'destination_storage_id', 'position', 'quantity'] as $field) $payload[$field] = $this->id($body[$field] ?? null);
        $payload['instance_id'] = ($body['instance_id'] ?? null) === null ? null : $this->id($body['instance_id']);
        $service = new WorldStorage(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->transfer($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionStorageTransferPreview(): array { return $this->storageTransfer(true); }
    public function actionStorageTransfer(): array { return $this->storageTransfer(false); }
    private function chestRepair(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $payload = ['storage_id' => $this->id($body['storage_id'] ?? null), 'container_inventory_id' => $this->id($body['container_inventory_id'] ?? null)];
        $service = new \common\modules\world\modules\craft\models\domain\WorldChestRepair(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->repair($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionChestRepairPreview(): array { return $this->chestRepair(true); }
    public function actionChestRepair(): array { return $this->chestRepair(false); }
    public function actionRecovery(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\modules\craft\models\domain\StorageRecovery(Yii::$app->db, $this->flags()))->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)));
    }
    private function recovery(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $inventory = $this->id($body['inventory_id'] ?? null); $user = (int)Yii::$app->user->id;
        $service = new \common\modules\world\modules\craft\models\domain\StorageRecovery(Yii::$app->db, $this->flags());
        if ($preview) return $service->preview($user, $inventory);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->recover($user, $body['request_key'], $inventory, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionRecoveryPreview(): array { return $this->recovery(true); }
    public function actionRecover(): array { return $this->recovery(false); }
}
