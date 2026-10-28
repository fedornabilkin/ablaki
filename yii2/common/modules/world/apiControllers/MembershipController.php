<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\domain\WorldOnboarding;
use common\modules\world\support\GameError;
use Yii;

class MembershipController extends LegacyController
{
    public function actionOnboarding(): array { return (new WorldOnboarding(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id); }
    public function actionJoinPreview(): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        return (new WorldOnboarding(Yii::$app->db, $this->flags()))->preview((int)Yii::$app->user->id, $this->id($body['settlement_id'] ?? null));
    }
    public function actionJoin(): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return (new WorldOnboarding(Yii::$app->db, $this->flags()))->join((int)Yii::$app->user->id, $body['request_key'], $this->id($body['settlement_id'] ?? null), $body['quote_id'], $body['expected_revisions']);
    }
}
