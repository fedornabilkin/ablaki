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
        $finite = (new domain\FiniteSupplies(Node::getDb()))->state($user);
        if ($finite) {
            $finite['gather_can_claim'] = false; $finite['gather_reason'] = null;
            if ($finite['gather_available']) {
                try { $plan = (new SupplyGrant(Node::getDb()))->plan($user, 'gather'); $finite['gather_can_claim'] = true; $finite['gather_items'] = $plan['items']; }
                catch (GameError $error) { $finite['gather_reason'] = $error->getMessage(); }
            }
            return $finite;
        }
        $period = (new \DateTimeImmutable('@' . time()))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->getTimestamp();
        return ['gather_available' => !(new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'gather'])->andWhere(['>=', 'created_at', $period])->exists(Node::getDb()),
            'gather_available_at' => $period + 86400, 'server_time' => time()];
    }
    public static function gather(int $nodeId, int $user, string $operation): array
    {
        $node = self::campsite($nodeId, $user);
        $db = Node::getDb(); $store = new CraftStorage($db); $store->operationId = $operation;
        $grant = new SupplyGrant($db); $plan = $grant->plan($user, 'gather');
        return ['message' => $grant->grant($store, $user, 'gather'), 'gathered_items' => $plan['items']] + self::state($nodeId, $user);
    }
}
