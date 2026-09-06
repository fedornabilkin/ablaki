<?php

namespace common\services\user;

use Yii;
use RuntimeException;
use yii\caching\FileCache;
use yii\mutex\FileMutex;

/** API activity is separate from the actual last login timestamp. */
class PresenceService
{
    const WINDOW_SECONDS = 300;
    private $cache;
    private $mutex;
    private const KEY = 'active-users';

    public function __construct()
    {
        $this->cache = new FileCache(['cachePath' => '@runtime/presence/cache']);
        $this->mutex = new FileMutex(['mutexPath' => '@runtime/presence/mutex']);
    }

    public function touch(int $userId, int $now = null): void
    {
        $now = $now ?? time();
        if (!$this->mutex->acquire(self::KEY, 2)) {
            throw new RuntimeException('Presence storage is busy.');
        }
        try {
            $active = $this->active($now);
            // An older parallel request must not move activity backwards.
            if (isset($active[$userId]) && $active[$userId] > $now - 60) {
                return;
            }
            $active[$userId] = $now;
            if (!$this->cache->set(self::KEY, $active, self::WINDOW_SECONDS + 1)) {
                throw new RuntimeException('Unable to store presence.');
            }
        } finally {
            $this->mutex->release(self::KEY);
        }
    }

    /** @return int[] */
    public static function onlineIds(int $now = null): array
    {
        return array_map('intval', array_keys((new self())->active($now ?? time())));
    }

    private function active(int $now): array
    {
        $active = $this->cache->get(self::KEY);
        return is_array($active) ? array_filter($active, function ($seen) use ($now) {
            return $seen >= $now - self::WINDOW_SECONDS;
        }) : [];
    }

    /** Presence is ancillary: a storage failure must not invalidate a valid session. */
    public static function recordActivity(int $userId): void
    {
        try {
            (new self())->touch($userId);
        } catch (\Throwable $error) {
            // Never log SQL, request headers or identity credentials here.
            Yii::warning('Unable to record user activity (' . get_class($error) . ').', __METHOD__);
        }
    }
}
