<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class OrderController extends LegacyController
{
    private function orderService(): \common\modules\world\modules\economy\models\domain\StarterOrders
    {
        return new \common\modules\world\modules\economy\models\domain\StarterOrders(Yii::$app->db, $this->flags(), $this->policy());
    }
    public function actionOrders($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'open');
        if (!is_string($status)) throw new GameError('INVALID_ORDER_FILTER', 'Некорректный статус.', 422);
        return $this->orderService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    public function actionOrderItems($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->orderService()->catalog((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    public function actionOrderStock($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->orderService()->stock((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('item_id')), $this->id(Yii::$app->request->get('page', 1)));
    }
    private function orderCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id)];
        if ($action === 'publish') {
            foreach (['item_id', 'quantity', 'per_user_limit', 'lifetime_hours'] as $field) $input[$field] = $this->id($body[$field] ?? null);
            if ($input['quantity'] > 1000000 || $input['per_user_limit'] > $input['quantity'] || $input['lifetime_hours'] > 720) throw new GameError('INVALID_ORDER_LIMIT', 'Проверьте количество, лимит на игрока и срок до 720 часов.', 422);
            if (!is_string($body['unit_price'] ?? null) || !is_string($body['purpose'] ?? null) || trim($body['purpose']) === '' || mb_strlen($body['purpose'], 'UTF-8') > 255 || ($body['initialize_starter_policy'] ?? null) !== true) throw new GameError('INVALID_ORDER', 'Укажите цену, назначение и подтвердите начальные условия стоянок.', 422);
            try { $price = \common\modules\world\modules\economy\value\Money::parse($body['unit_price']); }
            catch (\InvalidArgumentException $e) { throw new GameError('INVALID_AMOUNT', 'Цена должна быть десятичной строкой до четырёх знаков после точки.', 422); }
            catch (\OverflowException $e) { throw new GameError('INVALID_AMOUNT', 'Цена превышает допустимый предел.', 422); }
            if ($price->isZero() || $price->isNegative()) throw new GameError('INVALID_AMOUNT', 'Цена должна быть положительной.', 422);
            $input += ['unit_price' => $price->decimal(), 'purpose' => trim($body['purpose']), 'initialize_starter_policy' => true];
        } else {
            $input['order_id'] = $this->id($body['order_id'] ?? null);
            if ($action === 'deliver') {
                $input['inventory_id'] = $this->id($body['inventory_id'] ?? null); $input['quantity'] = $this->id($body['quantity'] ?? null);
                if (array_key_exists('income_node_id', $body)) $input['income_node_id'] = $this->id($body['income_node_id']);
                if ($input['quantity'] > 10000) throw new GameError('INVALID_QUANTITY', 'Можно сдать до 10000 предметов за раз.', 422);
            }
        }
        $service = $this->orderService(); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $action);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $action);
    }
    public function actionOrderPublishPreview($id): array { return $this->orderCommand($id, 'publish', true); }
    public function actionOrderPublish($id): array { return $this->orderCommand($id, 'publish', false); }
    public function actionOrderDeliverPreview($id): array { return $this->orderCommand($id, 'deliver', true); }
    public function actionOrderDeliver($id): array { return $this->orderCommand($id, 'deliver', false); }
    public function actionOrderCancelPreview($id): array { return $this->orderCommand($id, 'cancel', true); }
    public function actionOrderCancel($id): array { return $this->orderCommand($id, 'cancel', false); }
}
