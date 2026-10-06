<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class DemolitionController extends LegacyController
{
    private function demolitionService(): \common\modules\world\models\domain\WorldDemolition
    {
        return new \common\modules\world\models\domain\WorldDemolition(Yii::$app->db, $this->flags());
    }
    public function actionDemolition($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->demolitionService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionDemolitions($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->demolitionService()->history((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function demolitionCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->demolitionService();
        if ($preview) return $service->preview($user, $input);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionDemolishPreview($id): array { return $this->demolitionCommand($id, true); }
    public function actionDemolish($id): array { return $this->demolitionCommand($id, false); }
}
