<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\models\domain\SupplyGrant;
use common\modules\world\support\GameError;
use yii\db\Query;

final class Supplies
{
    private static function campsite(int $nodeId, int $user): Node
    {
        $node = Node::readable($nodeId, $user); $node->requireOwner($user);
        if ($node->status !== 'active') throw new GameError('NODE_UNAVAILABLE', 'Сначала завершите строительство.', 409);
        if ($node->node_type !== 'PLOT' || $node->plot_kind !== 'campsite') throw new GameError('NOT_A_SOURCE', 'Собирать ежедневное сырьё можно только на карте собственной стоянки.', 422);
        return $node;
    }
    public static function state(int $nodeId, int $user): array
    {
        self::campsite($nodeId, $user);
        $period = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        return ['gather_available' => !(new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'gather'])->andWhere(['>=', 'created_at', $period])->exists(Node::getDb()),
            'gather_available_at' => $period + 86400, 'server_time' => time()];
    }
    public static function gather(int $nodeId, int $user, string $operation): array
    {
        $node = self::campsite($nodeId, $user);
        $db = Node::getDb(); $store = new CraftStorage($db); $store->operationId = $operation;
        return ['message' => (new SupplyGrant($db))->grant($store, $user, 'gather')] + self::state($nodeId, $user);
    }
}
