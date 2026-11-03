<?php
namespace common\services\user;

/** Shared request leases let an explicit migration drain writers without stopping reads. */
final class FinancialMaintenance
{
    private static $lease;

    public static function directory(): string { return dirname(__DIR__, 2) . '/runtime/financial-maintenance'; }
    public static function paused(): bool { clearstatcache(true, self::directory() . '/paused'); return is_file(self::directory() . '/paused'); }

    /** Only known read actions are exempt; GET alone does not make a legacy route safe. */
    public static function readAllowed(string $route, string $method): bool
    {
        if ($method === 'OPTIONS' || $route === 'site/error') return true;
        if (!in_array($method, ['GET', 'HEAD'], true)) return false;
        $parts = explode('/', $route);
        if (count($parts) === 3 && $parts[0] === 'v1'
            && in_array($parts[1], ['orel', 'duel', 'saper', 'five'], true)
            && in_array($parts[2], ['index', 'my', 'history', 'history-kons', 'recent', 'summary', 'stakes', 'kon-count'], true)) return true;
        return in_array($route, [
            'site/index', 'site/about',
            'v1/user/index', 'v1/user/wall', 'v1/user/last', 'v1/user/profile', 'v1/user/data',
            'v1/user/referrals', 'v1/user/online', 'v1/user/online-count', 'v1/user/visited',
            'v1/history/index', 'v1/history/balance', 'v1/history/rating', 'v1/history/balance-type', 'v1/history/rating-type',
            'v1/stat/index', 'v1/stat/top', 'v1/stat/user', 'v1/stat/recent-games', 'v1/tips/random',
            'v1/bonus/available', 'v1/bonus/fund', 'v1/bonus/my-fund', 'v1/bonus/recipients',
            'v1/exchange/index', 'v1/exchange/my', 'v1/exchange/history', 'v1/exchange/available-count',
            'v1/transfer/index', 'v1/transfer/view', 'v1/transfer/history', 'v1/five/view',
            'v1/forum-theme/index', 'v1/forum-theme/view', 'v1/forum-theme/my',
            'v1/forum-comment/index', 'v1/forum-comment/view', 'v1/forum-comment/my', 'v1/forum-comment/gifts',
            'forum/default/index', 'forum/theme/index', 'forum/theme/view', 'forum/comment/index', 'forum/comment/view',
            'games/orel/index', 'games/orel/history', 'games/orel/last', 'games/orel/my',
            'games/saper/index', 'games/saper/history', 'games/saper/last', 'games/saper/my', 'games/saper/view',
            'games/duel/index', 'games/duel/history', 'games/duel/last', 'games/duel/my',
            'games/five/index', 'games/five/history', 'games/five/last', 'games/five/my',
        ], true);
    }

    public static function enterWriter(): bool
    {
        $directory = self::directory();
        // An additive compatibility release is inert until the operator prepares this directory.
        if (!is_dir($directory)) return true;
        // Let an already admitted request finish, including any nested controller action.
        if (is_resource(self::$lease)) return true;
        if (self::paused()) return false;
        if (!is_resource(self::$lease)) {
            $path = $directory . '/writers.lock';
            if (is_link($path)) throw new \RuntimeException('Invalid financial maintenance lock.');
            $lease = @fopen($path, 'r');
            if (!$lease) throw new \RuntimeException('Financial maintenance lock is unavailable.');
            if (!flock($lease, LOCK_SH | LOCK_NB)) { fclose($lease); return false; }
            self::$lease = $lease; // Held until request/process shutdown, including transaction completion.
        }
        if (self::paused()) { self::release(); return false; }
        return true;
    }

    public static function release(): void
    {
        if (is_resource(self::$lease)) { flock(self::$lease, LOCK_UN); fclose(self::$lease); }
        self::$lease = null;
    }
}
