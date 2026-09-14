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
        return [
            'id' => (int)$user->id,
            'username' => $user->username,
            'created_at' => $user->getAttribute('created_at'),
            'last_login_at' => $user->getAttribute('last_login_at'),
            'is_online' => in_array((int)$user->id, PresenceService::onlineIds(), true),
            'person' => $profile === null ? null : [
                'id' => (int)$profile->id,
                'bonus_count' => $profile->bonus_count,
                'refovod' => $profile->refovod,
                'rating' => UserHelper::ratingRound($profile->rating),
                'description' => $profile->getAttribute('description'),
                'forum_credits_sent' => (int)(new Query())->from('{{%forum_comment_gift}}')
                    ->where(['user_id' => $user->id])->count(),
            ],
        ];
    }
}
