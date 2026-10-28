<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class ProfessionController extends LegacyController
{
    private function progressionService(): \common\modules\world\modules\progression\models\domain\WorldProgression
    {
        return new \common\modules\world\modules\progression\models\domain\WorldProgression(Yii::$app->db, $this->flags(), $this->policy());
    }
    public function actionProfessions(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->progressionService()->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function professionCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->progressionService(); $user = (int)Yii::$app->user->id;
        $input = $action === 'publish' ? $service->publication($body) : ['profession_id' => $this->id($id)];
        if ($preview) return $service->preview($user, $action, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $action, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionProfessionPreview($id, string $action): array { return $this->professionCommand($id, $action, true); }
    public function actionProfessionExecute($id, string $action): array { return $this->professionCommand($id, $action, false); }
    public function actionProfessionPublishPreview(): array { return $this->professionCommand(null, 'publish', true); }
    public function actionProfessionPublish(): array { return $this->professionCommand(null, 'publish', false); }
}
