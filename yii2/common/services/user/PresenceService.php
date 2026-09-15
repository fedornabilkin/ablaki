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
            $active = $this->recent($now);
            // An older parallel request must not move activity backwards.
            if (isset($active[$userId]) && $active[$userId] > $now - 60) {
                return;
            }
            $active[$userId] = $now;
            if (!$this->cache->set(self::KEY, $active, 86400 + self::WINDOW_SECONDS)) {
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

    /** Visits today survive expiry of the five-minute online window. */
    public static function todayIds(int $now = null): array
    {
        $now = $now ?? time();
        list($start, $end) = self::dayBounds($now);
        return array_map('intval', array_keys(array_filter((new self())->recent($now), static function ($seen) use ($start, $end, $now) {
            return $seen >= $start && $seen < $end && $seen <= $now;
        })));
    }

    public static function dayBounds(int $now = null): array
    {
        $day = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        return [$day->getTimestamp(), $day->modify('+1 day')->getTimestamp()];
    }

    private function recent(int $now): array
    {
        $active = $this->cache->get(self::KEY);
        $start = min(self::dayBounds($now)[0], $now - self::WINDOW_SECONDS);
        return is_array($active) ? array_filter($active, static function ($seen) use ($start) {
            return is_int($seen) && $seen >= $start;
        }) : [];
    }

    private function active(int $now): array
    {
        return array_filter($this->recent($now), function ($seen) use ($now) {
            return $seen >= $now - self::WINDOW_SECONDS;
        });
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
