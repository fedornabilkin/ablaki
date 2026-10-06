<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class NotificationController extends LegacyController
{
    private function notificationService(): \common\modules\world\models\domain\WorldNotifications { return new \common\modules\world\models\domain\WorldNotifications(Yii::$app->db, $this->flags()); }
    public function actionNotifications(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'all'); $type = Yii::$app->request->get('type', '');
        if (!is_string($status) || !is_string($type)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        return $this->notificationService()->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status, $type);
    }
    public function actionNotificationRead($id): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        return $this->notificationService()->read((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionNotificationPreferences(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->notificationService()->preferences((int)Yii::$app->user->id);
    }
    public function actionNotificationConfigure(): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректные настройки.', 422);
        return $this->notificationService()->configure((int)Yii::$app->user->id, $body);
    }
}
