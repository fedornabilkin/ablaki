<?php

namespace common\services\user;

use yii\db\Connection;
use yii\db\Query;

/** API activity is separate from the actual last login timestamp. */
class PresenceService
{
    const WINDOW_SECONDS = 300;
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function touch(int $userId, int $now = null): void
    {
        $now = $now ?? time();
        $lastSeen = (new Query())->select('last_seen_at')->from('{{%user_presence}}')
            ->where(['user_id' => $userId])->scalar($this->db);
        if ($lastSeen !== false && (int)$lastSeen >= $now - 60) {
            return;
        }
        // An older parallel request must not move activity backwards.
        if ($lastSeen === false) {
            $this->db->createCommand()->upsert('{{%user_presence}}', [
                'user_id' => $userId, 'last_seen_at' => $now,
            ], false)->execute();
        }
        $this->db->createCommand()->update('{{%user_presence}}', ['last_seen_at' => $now],
            ['and', ['user_id' => $userId], ['<', 'last_seen_at', $now - 60]])->execute();
    }

    public static function onlineIds(int $now = null): Query
    {
        return (new Query())->select('user_id')->from('{{%user_presence}}')
            ->where(['>=', 'last_seen_at', ($now ?? time()) - self::WINDOW_SECONDS]);
    }
}
