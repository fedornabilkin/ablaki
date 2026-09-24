<?php
namespace common\services\user;

use yii\db\Connection;

/** Legacy user.latest_activity stores Moscow wall time (DATETIME, without a zone). */
class UserActivity
{
    public static function date(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s');
    }

    public static function timestamp(?string $date): ?int
    {
        if (!$date || substr($date, 0, 4) === '0000') return null;
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('Europe/Moscow'));
        return $value && $value->format('Y-m-d H:i:s') === $date ? $value->getTimestamp() : null;
    }

    public static function available(Connection $db): bool
    {
        $table = $db->getTableSchema('{{%user}}');
        // API schema caches can outlive a deployment; discover the additive migration immediately.
        if ($table && !isset($table->columns['latest_activity'])) $table = $db->getTableSchema('{{%user}}', true);
        return $table && isset($table->columns['latest_activity']);
    }

    public static function touch(Connection $db, int $userId, int $now): void
    {
        if ($userId < 1 || !self::available($db)) return;
        $date = self::date($now);
        // One atomic write on every authenticated request, even within the same minute.
        // A delayed request cannot move the timestamp backwards.
        $db->createCommand()->update('{{%user}}', ['latest_activity' => $date], [
            'and', ['id' => $userId], ['or', ['latest_activity' => null], ['<=', 'latest_activity', $date]],
        ])->execute();
    }
}
