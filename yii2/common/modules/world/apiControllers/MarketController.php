<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\domain\NpcMarket;
use common\modules\world\support\GameError;
use Yii;

final class MarketController extends CoreController
{
    public function actionIndex($id): array
    {
        $this->flags()->requireFlag('storage_v2');
        return (new NpcMarket(Yii::$app->db))->listing($this->id($id), (int)Yii::$app->user->id);
    }

    public function actionCreate($id): array
    {
        $this->flags()->requireFlag('storage_v2');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !in_array($body['direction'] ?? null, ['buy', 'sell'], true)
            || !is_int($body['quantity'] ?? null) || !is_int($body['profile_version'] ?? null)
            || !is_string($body['unit_price_cr'] ?? null) || !preg_match('/^\d{1,12}\.\d{4}$/D', $body['unit_price_cr'])) {
            throw new GameError('INVALID_TRADE', 'Подтвердите количество и цену сделки.', 422);
        }
        $input = ['city_id' => $this->id($id), 'direction' => $body['direction'], 'quantity' => $body['quantity'],
            'inventory_id' => $this->id($body['inventory_id'] ?? null), 'profile_version' => $body['profile_version'], 'unit_price_cr' => $body['unit_price_cr']];
        return $this->command('world.market.trade', $input, fn(string $operation): array => (new NpcMarket(Yii::$app->db))->trade((int)Yii::$app->user->id, $input, $operation));
    }
}
