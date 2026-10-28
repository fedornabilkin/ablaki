<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class PremisesController extends LegacyController
{
    private function premisesService(): \common\modules\world\models\domain\WorldPremises
    {
        return new \common\modules\world\models\domain\WorldPremises(Yii::$app->db, $this->flags(), $this->policy());
    }
    public function actionPremises($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->premisesService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function premisesCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->premisesService(); $input = ['node_id' => $this->id($id)];
        if ($action === 'publish') $input += $service->publication($body);
        else $input['offer_id'] = $this->id($body['offer_id'] ?? null);
        $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $action);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $action);
    }
    public function actionPremisesPublishPreview($id): array { return $this->premisesCommand($id, 'publish', true); }
    public function actionPremisesPublish($id): array { return $this->premisesCommand($id, 'publish', false); }
    public function actionPremisesBuyPreview($id): array { return $this->premisesCommand($id, 'buy', true); }
    public function actionPremisesBuy($id): array { return $this->premisesCommand($id, 'buy', false); }
    public function actionPremisesWithdrawPreview($id): array { return $this->premisesCommand($id, 'withdraw', true); }
    public function actionPremisesWithdraw($id): array { return $this->premisesCommand($id, 'withdraw', false); }
}
