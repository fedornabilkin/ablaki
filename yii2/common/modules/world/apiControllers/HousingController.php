<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class HousingController extends LegacyController
{
    public function actionHousing($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\models\domain\WorldHousing(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionEquipmentWear($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $kind = Yii::$app->request->get('kind', ''); $search = Yii::$app->request->get('q', '');
        if (!is_string($kind) || !is_string($search)) throw new GameError('INVALID_WEAR_FILTER', 'Некорректный фильтр износа.', 422);
        return (new \common\modules\world\modules\craft\models\domain\EquipmentWearHistory(Yii::$app->db))->state((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $kind, trim($search), $this->flags());
    }
    private function housingCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие с жильём.', 422);
        $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id;
        $service = new \common\modules\world\models\domain\WorldHousing(Yii::$app->db, $this->flags());
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionHousingPreview($id, string $operation): array { return $this->housingCommand($id, $operation, true); }
    public function actionHousingExecute($id, string $operation): array { return $this->housingCommand($id, $operation, false); }
}
