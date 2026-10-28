<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class WarehouseController extends LegacyController
{
    private function warehouseService(): \common\modules\world\models\domain\WorldWarehouse { return new \common\modules\world\models\domain\WorldWarehouse(Yii::$app->db, $this->flags()); }
    public function actionWarehouse($id): array { return $this->warehouseService()->state((int)Yii::$app->user->id, $this->id($id)); }
    private function warehouseCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_bool($body['top_up'] ?? false)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id), 'top_up' => $body['top_up'] ?? false]; $user = (int)Yii::$app->user->id;
        if ($preview) return $this->warehouseService()->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $this->warehouseService()->execute($user, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionWarehouseExpandPreview($id): array { return $this->warehouseCommand($id, true); }
    public function actionWarehouseExpand($id): array { return $this->warehouseCommand($id, false); }
}
