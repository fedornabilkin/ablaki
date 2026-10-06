<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class FinanceController extends LegacyController
{
    public function actionObligations($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\modules\economy\models\domain\NodeEconomy(Yii::$app->db, $this->flags()))->obligations((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)));
    }
    public function actionTreasuryReceipts($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'open');
        if (!is_string($status)) throw new GameError('INVALID_RECEIPT_FILTER', 'Некорректный статус поступлений.', 422);
        return (new \common\modules\world\modules\economy\models\domain\TreasuryReceipts(Yii::$app->db, $this->flags()))->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    public function actionFinanceReport($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $kind = Yii::$app->request->get('kind', 'all'); $direction = Yii::$app->request->get('direction', 'all');
        if (!is_string($kind) || !is_string($direction)) throw new GameError('INVALID_FINANCE_FILTER', 'Некорректные фильтры отчёта.', 422);
        return (new \common\modules\world\modules\economy\models\domain\NodeFinanceReport(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $kind, $direction);
    }
    public function actionFinanceHierarchy($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\modules\economy\models\domain\HierarchyFinanceReport(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function treasury($id, bool $pay, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id)];
        if ($pay) $input['obligation_id'] = $this->id($body['obligation_id'] ?? null);
        $service = new \common\modules\world\modules\economy\models\domain\TreasuryCommands(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $pay);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $pay);
    }
    public function actionCollectPreview($id): array { return $this->treasury($id, false, true); }
    public function actionCollect($id): array { return $this->treasury($id, false, false); }
    public function actionPayPreview($id): array { return $this->treasury($id, true, true); }
    public function actionPay($id): array { return $this->treasury($id, true, false); }
    private function financePolicy($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $this->policy()->requireAdmin(); $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = \common\modules\world\modules\economy\models\domain\CollectionPolicy::input($body, $this->id($id));
        $service = new \common\modules\world\modules\economy\models\domain\CollectionPolicy(Yii::$app->db, $this->flags(), $this->policy()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->publish($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionFinancePolicyPreview($id): array { return $this->financePolicy($id, true); }
    public function actionFinancePolicy($id): array { return $this->financePolicy($id, false); }
}
