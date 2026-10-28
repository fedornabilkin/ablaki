<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class BudgetController extends LegacyController
{
    public function actionEconomy($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\modules\economy\models\domain\NodeEconomy(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)));
    }
    private function investment($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['amount'] ?? null) || !is_string($body['purpose'] ?? null) || trim($body['purpose']) === '' || mb_strlen($body['purpose'], 'UTF-8') > 255) throw new GameError('INVALID_INVESTMENT', 'Укажите сумму строкой и назначение вложения.', 422);
        try { $amount = \common\modules\world\modules\economy\value\Money::parse($body['amount']); }
        catch (\InvalidArgumentException $e) { throw new GameError('INVALID_AMOUNT', 'Укажите сумму с точностью до четырёх знаков после точки.', 422); }
        catch (\OverflowException $e) { throw new GameError('INVALID_AMOUNT', 'Сумма превышает допустимый предел.', 422); }
        if ($amount->isNegative() || $amount->isZero()) throw new GameError('INVALID_AMOUNT', 'Укажите положительную сумму.', 422);
        $payload = ['node_id' => $this->id($id), 'amount' => $amount->decimal(), 'purpose' => trim($body['purpose'])];
        $service = new \common\modules\world\modules\economy\models\domain\NodeEconomy(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->invest($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionInvestPreview($id): array { return $this->investment($id, true); }
    public function actionInvest($id): array { return $this->investment($id, false); }
    private function budgetGrant($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['amount'] ?? null) || !is_string($body['purpose'] ?? null)
            || trim($body['purpose']) === '' || mb_strlen($body['purpose'], 'UTF-8') > 255) throw new GameError('INVALID_GRANT', 'Укажите сумму и назначение перевода.', 422);
        try { $amount = \common\modules\world\modules\economy\value\Money::parse($body['amount']); }
        catch (\InvalidArgumentException $e) { throw new GameError('INVALID_AMOUNT', 'Укажите сумму с точностью до четырёх знаков после точки.', 422); }
        catch (\OverflowException $e) { throw new GameError('INVALID_AMOUNT', 'Сумма превышает допустимый предел.', 422); }
        if ($amount->isZero() || $amount->isNegative()) throw new GameError('INVALID_AMOUNT', 'Укажите положительную сумму.', 422);
        $input = ['source_node_id' => $this->id($id), 'destination_node_id' => $this->id($body['destination_node_id'] ?? null),
            'amount' => $amount->decimal(), 'purpose' => trim($body['purpose'])];
        $service = new \common\modules\world\modules\economy\models\domain\BudgetGrants(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionBudgetGrantPreview($id): array { return $this->budgetGrant($id, true); }
    public function actionBudgetGrant($id): array { return $this->budgetGrant($id, false); }
}
