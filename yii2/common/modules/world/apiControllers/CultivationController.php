<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class CultivationController extends LegacyController
{
    private function cultivationService(): \common\modules\world\models\domain\WorldCultivation { return new \common\modules\world\models\domain\WorldCultivation(Yii::$app->db, $this->flags(), $this->policy()); }
    public function actionCrops(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->cultivationService()->crops($this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    public function actionCultivation($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->cultivationService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function cultivationCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->cultivationService(); $user = (int)Yii::$app->user->id;
        $input = $action === 'publish' ? $service->publication($body) : ['bed_id' => $this->id($id)];
        if ($action === 'sow') $input['crop_revision_id'] = $this->id($body['crop_revision_id'] ?? null);
        if ($preview) return $service->preview($user, $action, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $action, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionCultivationPreview($id, string $action): array { return $this->cultivationCommand($id, $action, true); }
    public function actionCultivationExecute($id, string $action): array { return $this->cultivationCommand($id, $action, false); }
    public function actionCropPublishPreview(): array { return $this->cultivationCommand(null, 'publish', true); }
    public function actionCropPublish(): array { return $this->cultivationCommand(null, 'publish', false); }
}
