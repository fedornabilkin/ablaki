<?php

namespace common\services\user;

use common\helpers\UserHelper;
use common\models\user\User;
use yii\db\Query;

/** Explicit public profile contract; never serialize the underlying identity attributes. */
class PublicProfile
{
    public static function fromUser(?User $user): ?array
    {
        if ($user === null) return null;
        $profile = $user->person;
        try { $online = in_array((int)$user->id, PresenceService::onlineIds(), true); }
        catch (\Throwable $error) { $online = false; }
        return [
            'id' => (int)$user->id,
            'username' => $user->username,
            'created_at' => $user->getAttribute('created_at'),
            'last_login_at' => $user->getAttribute('last_login_at'),
            'latest_activity' => UserActivity::timestamp($user->getAttribute('latest_activity')),
            'is_online' => $online,
            'person' => $profile === null ? null : [
                'id' => (int)$profile->id,
                'bonus_count' => $profile->bonus_count,
                'refovod' => $profile->refovod,
                'rating' => UserHelper::ratingRound($profile->rating),
                'description' => $profile->getAttribute('description'),
                'forum_credits_sent' => \common\modules\forum\services\CommentGiftSchema::sent(\Yii::$app->db, (int)$user->id),
            ],
        ];
    }
}
