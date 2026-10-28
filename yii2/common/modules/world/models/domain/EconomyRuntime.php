<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Only an explicitly published database revision affects commands. No process-wide cache. */
final class EconomyRuntime
{
    /** Only persisted facts, never arbitrary command payloads or credentials. */
    public static function operationContext(Connection $db, array $payload): array
    {
        $revision = self::current($db); if (!$revision) return [];
        $context = ['profile_id' => $revision['id'], 'profile_version' => $revision['version']];
        foreach (['node_id', 'mining_node_id', 'parent_id', 'city_id'] as $key) if (isset($payload[$key]) && (int)$payload[$key] > 0) {
            $context['node_id'] = (int)$payload[$key]; break;
        }
        return ['context_json' => \common\modules\world\support\CanonicalJson::encode($context)];
    }
    public static function rawCodes(Connection $db): array
    {
        $revision = self::current($db); if (!$revision) return [];
        $codes = [$revision['profile']['crop']['yield'] => true];
        foreach ($revision['profile']['actions'] as $action) if ($action['quota'] === 'extraction_daily') foreach ($action['output'] as $code => $quantity) $codes[$code] = true;
        return array_keys($codes);
    }
    public static function current(Connection $db): ?array
    {
        if (!$db->schema->getTableSchema('world_economy_current')) return null;
        $query = (new Query())->select('p.*')->from(['c' => 'world_economy_current'])->innerJoin(['p' => 'world_economy_profile'], 'p.id=c.profile_id')->where(['c.id' => 1]);
        $schema = $db->schema->getTableSchema('world_node');
        if ($schema && isset($schema->columns['settings_json'])) $query->addSelect(['world_clock_settings' => 'n.settings_json'])
            ->leftJoin(['r' => 'world_registry'], 'r.id=1')->leftJoin(['n' => 'world_node'], 'n.id=r.active_world_id');
        $row = $query->one($db);
        if (!$row) return null;
        $profile = json_decode($row['config_json'], true, 64, JSON_THROW_ON_ERROR);
        $settings = json_decode($row['world_clock_settings'] ?? '{}', true) ?: [];
        return ['id' => (int)$row['id'], 'version' => (int)$row['version'], 'digest' => $row['digest'], 'raw_profile' => $profile, 'clock_settings' => $settings,
            'profile' => \common\modules\world\models\WorldTime::runtimeProfile($db, $profile, $settings)];
    }
    public static function admission(Connection $db, int $user, bool $admin = false): array
    {
        $revision = self::current($db);
        $restricted = $revision !== null && ($revision['profile']['closed_test']['restricted'] ?? true);
        $participant = $restricted ? (new Query())->from('world_test_participant')->where(['user_id' => $user, 'enabled' => 1])->one($db) : null;
        $auth = \Yii::$app && \Yii::$app->has('authManager') ? \Yii::$app->authManager : null;
        $rbac = $auth && ($auth->checkAccess($user, 'world-closed-test') || $auth->checkAccess($user, 'world-manage'));
        return ['allowed' => !$restricted || $admin || $rbac, 'restricted' => (bool)$restricted,
            'cohort' => $participant ? $participant['cohort'] : null, 'profile_version' => $revision ? $revision['version'] : null];
    }
    public static function requireAccess(Connection $db, int $user, bool $admin = false): void
    {
        if (!self::admission($db, $user, $admin)['allowed']) throw new GameError('CLOSED_TEST_ACCESS_REQUIRED', 'Мир открыт для участников закрытого тестирования. Можно сообщить на форуме о желании принять участие в тестировании.', 403);
    }
    public static function world(Connection $db): int
    {
        $id = (new Query())->select('active_world_id')->from('world_registry')->where(['id' => 1])->scalar($db);
        if (!$id) throw new GameError('WORLD_NOT_READY', 'Мир ещё не подготовлен.', 503);
        return (int)$id;
    }
    public static function fact(Connection $db, int $user, string $code, int $quantity, string $operation): void
    {
        $profile = self::current($db); if (!$profile) return;
        if (!$db->getTransaction() || $quantity < 1) throw new \LogicException('Facts require the successful command transaction.');
        if ((new Query())->from('world_economy_fact_event')->where(['operation_id' => $operation, 'code' => $code])->exists($db)) return;
        $world = self::world($db); $where = ['world_id' => $world, 'user_id' => $user, 'code' => $code];
        $row = (new Query())->from('world_economy_fact')->where($where)->one($db);
        $cohort = (new Query())->select('cohort')->from('world_test_participant')->where(['user_id' => $user, 'enabled' => 1])->scalar($db);
        $db->createCommand()->insert('world_economy_fact_event', $where + ['quantity' => $quantity, 'operation_id' => $operation, 'profile_id' => $profile['id'], 'cohort' => $cohort === false ? null : $cohort, 'created_at' => time()])->execute();
        if ($row) $db->createCommand()->update('world_economy_fact', ['quantity' => (int)$row['quantity'] + $quantity], ['id' => $row['id']])->execute();
        else $db->createCommand()->insert('world_economy_fact', $where + ['quantity' => $quantity, 'first_operation_id' => $operation, 'first_at' => time()])->execute();
    }
}
