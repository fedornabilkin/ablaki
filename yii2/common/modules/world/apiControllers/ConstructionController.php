<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class ConstructionController extends LegacyController
{
    private function constructionService(): \common\modules\world\models\domain\WorldConstruction
    {
        return new \common\modules\world\models\domain\WorldConstruction(Yii::$app->db, $this->flags());
    }
    public function actionConstruction($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', '');
        if (!is_string($status)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        return $this->constructionService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    private function constructionCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->constructionService();
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionConstructionPreview($id, string $operation): array { return $this->constructionCommand($id, $operation, true); }
    public function actionConstructionExecute($id, string $operation): array { return $this->constructionCommand($id, $operation, false); }
}
