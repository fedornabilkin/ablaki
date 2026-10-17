<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** HP 0..100 is separate from night illness; food never erases an unprotected night. */
final class WorldFood
{
    public static function state(Connection $db, int $user): array
    {
        $revision = EconomyRuntime::current($db);
        $health = $revision ? (new Query())->from('world_player_health')->where(['world_id' => EconomyRuntime::world($db), 'user_id' => $user])->one($db) : null;
        $foods = [];
        if ($revision) {
            $stock = (new CraftStorage($db))->quantities($user);
            foreach ($revision['profile']['items'] as $code => $item) if (isset($item['restore_health'])) {
                $id = (int)(new Query())->select('id')->from('craft_item')->where(['code' => $code, 'active' => 1])->scalar($db);
                $foods[] = ['code' => $code, 'name' => $item['name'], 'restore_hp' => $item['restore_health'], 'quantity' => $stock[$id] ?? 0];
            }
        }
        return ['hp' => $health ? (int)$health['hp'] : 100, 'max_hp' => 100, 'revision' => $health ? (int)$health['revision'] : 0, 'foods' => $foods, 'server_time' => time()];
    }
    private static function change(Connection $db, int $user, int $delta, string $kind, string $operation): array
    {
        if (!$db->getTransaction()) throw new \LogicException('Health requires the game transaction.');
        $revision = EconomyRuntime::current($db);
        if (!$revision) return self::state($db, $user);
        if ((new Query())->from('world_health_event')->where(['operation_id' => $operation])->exists($db)) return self::state($db, $user);
        $state = self::state($db, $user); $where = ['world_id' => EconomyRuntime::world($db), 'user_id' => $user];
        $after = max(0, min(100, $state['hp'] + $delta));
        if ($state['revision']) {
            if ($db->createCommand()->update('world_player_health', ['hp' => $after, 'revision' => $state['revision'] + 1], $where + ['revision' => $state['revision']])->execute() !== 1) throw new \RuntimeException('Health changed concurrently.');
        } else $db->createCommand()->insert('world_player_health', $where + ['hp' => $after, 'revision' => 1])->execute();
        $db->createCommand()->insert('world_health_event', $where + ['operation_id' => $operation, 'hp_before' => $state['hp'], 'hp_after' => $after,
            'kind' => $kind, 'profile_id' => $revision['id'], 'created_at' => time()])->execute();
        return self::state($db, $user);
    }
    public static function night(Connection $db, int $user, bool $protected, string $operation): void
    {
        if (!$protected && EconomyRuntime::current($db)) self::change($db, $user, -15, 'unprotected_night', $operation);
    }
    public static function consume(Connection $db, int $user, string $code, int $expected, string $operation): array
    {
        $revision = EconomyRuntime::current($db); $food = $revision['profile']['items'][$code] ?? null;
        if (!$food || !isset($food['restore_health'])) throw new GameError('NOT_FOOD', 'Выберите еду из действующего каталога.', 422);
        $state = self::state($db, $user);
        if ($expected !== $state['revision']) throw new GameError('HEALTH_CHANGED', 'Здоровье изменилось. Проверьте результат перед применением.', 409);
        if ($state['hp'] === 100) throw new GameError('FOOD_NOT_NEEDED', 'Здоровье полностью восстановлено; еда сохранена.', 409);
        $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1, 'kind' => 'consumable'])->one($db);
        if (!$item) throw new GameError('FOOD_UNAVAILABLE', 'Это блюдо временно недоступно.', 409);
        $store = new CraftStorage($db); $store->operationId = $operation; $store->move($user, $item, -1);
        $result = self::change($db, $user, $food['restore_health'], 'food:' . $code, $operation);
        $db->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => 'food', 'item_id' => $item['id'], 'quantity' => 1, 'credit_change' => 0, 'created_at' => time()])->execute();
        return ['health' => $result, 'restored_hp' => $result['hp'] - $state['hp']];
    }
}
