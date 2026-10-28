<?php
namespace common\modules\world\models;

class Membership extends Record
{
    public static function tableName() { return 'world_membership'; }
    public static function ownsSite(int $user, int $world, int $site): bool
    {
        return self::find()->where(['user_id' => $user, 'world_id' => $world, 'starter_site_id' => $site])->exists();
    }
    public static function forUser(int $user): ?self { return self::findOne(['user_id' => $user, 'world_id' => Registry::worldId()]); }
}
