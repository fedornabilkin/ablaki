<?php

namespace common\services\user;

use common\helpers\UserHelper;
use common\models\user\User;
use yii\db\Connection;
use yii\db\Query;

/** Explicit public profile contract; never serialize the underlying identity attributes. */
class PublicProfile
{
    /** Small public identities for map owners; one batch, no identity attributes. */
    public static function identities(Connection $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) { return $id > 0; })));
        if (!$ids) return [];
        $rows = (new Query())->select(['id', 'username'])->from('{{%user}}')->where(['id' => $ids])->all($db);
        try { $online = array_fill_keys(PresenceService::onlineIds(), true); }
        catch (\Throwable $error) { $online = []; }
        $result = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $result[$id] = ['id' => $id, 'username' => $row['username'], 'is_online' => isset($online[$id])];
        }
        return $result;
    }

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
