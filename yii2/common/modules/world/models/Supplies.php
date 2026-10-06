<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\models\domain\SupplyGrant;
use common\modules\world\support\GameError;
use yii\db\Query;

final class Supplies
{
    public static function gather(int $nodeId, int $user, string $operation): array
    {
        $node = Node::readable($nodeId, $user); $node->requireOwner($user);
        if ($node->status !== 'active') throw new GameError('NODE_UNAVAILABLE', 'Сначала завершите строительство.', 409);
        $db = Node::getDb(); $store = new CraftStorage($db); $store->operationId = $operation;
        if ((int)$node->hierarchy_level === 4) {
            $message = (new SupplyGrant($db))->grant($store, $user, 'gather');
            return ['message' => $message];
        }
        if ($node->building_kind !== 'mine' || (int)$node->hierarchy_level !== 5) throw new GameError('NOT_A_SOURCE', 'Добыча доступна на стоянке и в шахте.', 422);
        $pick = CraftItem::findOne(['code' => 'classic-pickaxe']); $quantities = $store->quantities($user);
        if (!$pick || empty($quantities[$pick->id])) throw new GameError('PICKAXE_REQUIRED', 'Для работы в шахте нужна кирка в рюкзаке.', 409);
        $period = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        if ((new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'mine'])->andWhere(['>=', 'created_at', $period])->exists($db))
            throw new GameError('ALREADY_GATHERED', 'На сегодня добыча завершена.', 409);
        foreach (['classic-ore' => 12, 'classic-coal' => 12, 'classic-stone' => 20] as $code => $quantity) {
            $item = CraftItem::find()->where(['code' => $code, 'active' => 1])->asArray()->one();
            if (!$item) throw new GameError('RESOURCE_UNAVAILABLE', 'Ресурс недоступен.', 503);
            $store->move($user, $item, $quantity);
        }
        $db->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => 'mine', 'quantity' => 44, 'credit_change' => 0, 'created_at' => time()])->execute();
        return ['message' => 'Руда, уголь и камень получены.'];
    }
}
