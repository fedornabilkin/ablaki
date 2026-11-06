<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\{Connection, Query};

/** A short tutorial crop cannot replace the regular crop after the first three sowings. */
final class IntroCropPolicy
{
    public static function used(Connection $db, int $user): int
    {
        return (int)(new Query())->from(['cy' => 'world_crop_cycle'])
            ->innerJoin(['r' => 'world_crop_revision'], 'r.id=cy.crop_revision_id')
            ->innerJoin(['c' => 'world_crop'], 'c.id=r.crop_id')
            ->where(['cy.owner_user_id' => $user, 'c.code' => 'starter-carrot'])->count('*', $db);
    }

    public static function requireAvailable(Connection $db, int $user, array $revision): void
    {
        $limit = (json_decode($revision['config_json'] ?? '{}', true) ?: [])['intro_sow_limit'] ?? null;
        if ($limit !== null && self::used($db, $user) >= $limit) {
            throw new GameError('INTRO_CROP_COMPLETED', 'Учебные посадки завершены. Выберите обычную морковь: она даёт больше урожая.', 409);
        }
    }
}
