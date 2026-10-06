<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class AchievementController extends LegacyController
{
    private function achievementService(): \common\modules\world\modules\progression\models\domain\WorldAchievements { return new \common\modules\world\modules\progression\models\domain\WorldAchievements(Yii::$app->db, $this->flags(), $this->policy()); }
    public function actionAchievements(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'all');
        if (!is_string($status)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        return $this->achievementService()->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    private function achievementPublish(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->achievementService(); $input = $service->input($body); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->publish($user, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionAchievementPublishPreview(): array { return $this->achievementPublish(true); }
    public function actionAchievementPublish(): array { return $this->achievementPublish(false); }
}
