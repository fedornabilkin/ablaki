<?php

namespace common\modules\games\service;

use DateTimeImmutable;
use DateTimeZone;
use yii\web\BadRequestHttpException;

class HistoryPeriod
{
    /** Calendar days in Moscow; week/month include today and the preceding 6/29 days. */
    public static function apply($query, string $column, $period, int $now = null): void
    {
        if (!is_string($period) || !in_array($period, ['today', 'yesterday', 'week', 'month', 'all'], true)) {
            throw new BadRequestHttpException('Invalid history period.');
        }
        if ($period === 'all') return;
        $today = (new DateTimeImmutable('@' . ($now ?? time())))
            ->setTimezone(new DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $days = ['today' => 0, 'yesterday' => 1, 'week' => 6, 'month' => 29];
        $from = $today->modify('-' . $days[$period] . ' days');
        $until = $period === 'yesterday' ? $today : $today->modify('+1 day');
        $query->andWhere(['>=', $column, $from->getTimestamp()])
            ->andWhere(['<', $column, $until->getTimestamp()]);
    }
}
