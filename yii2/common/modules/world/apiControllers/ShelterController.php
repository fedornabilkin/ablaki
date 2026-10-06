<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class ShelterController extends LegacyController
{
    public function actionShelter($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\models\domain\WorldShelter(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionNights($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $outcome = Yii::$app->request->get('outcome', '');
        if (!is_string($outcome)) throw new GameError('INVALID_NIGHT_FILTER', 'Некорректный фильтр ночей.', 422);
        return (new \common\modules\world\models\domain\WorldNights(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $outcome);
    }
    private function shelterCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_bool($body['direct_deploy'] ?? null) || !is_bool($body['end_lodging'] ?? null)) throw new GameError('INVALID_COMMAND', 'Укажите вариант размещения и подтверждение прекращения ночлега.', 422);
        $input = ['node_id' => $this->id($id), 'direct_deploy' => $body['direct_deploy'], 'end_lodging' => $body['end_lodging']];
        $service = new \common\modules\world\models\domain\WorldShelter(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionShelterPreview($id, string $operation): array { return $this->shelterCommand($id, $operation, true); }
    public function actionShelterExecute($id, string $operation): array { return $this->shelterCommand($id, $operation, false); }
}
