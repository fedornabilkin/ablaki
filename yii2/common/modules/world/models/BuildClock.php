<?php
namespace common\modules\world\models;

/** Checkpoint every live builder before completion; the last heartbeat cannot take others' work. */
final class BuildClock
{
    public static function checkpoint(Build $build, int $now): void
    {
        $works = $build->getWork()->andWhere(['ended_at' => null])->orderBy('id')->all();
        $elapsed = []; $total = 0;
        foreach ($works as $work) {
            $end = min($now, (int)$work->last_seen_at + BuildWork::HEARTBEAT_SECONDS);
            $elapsed[$work->id] = max(0, $end - (int)$work->accounted_at);
            $total += $elapsed[$work->id]; $work->accounted_at = max((int)$work->accounted_at, $end);
        }
        $remaining = max(0, (int)$build->required_seconds - (int)$build->worked_seconds);
        $credited = min($total, $remaining); $shares = []; $fractions = []; $assigned = 0;
        foreach ($elapsed as $id => $seconds) {
            $shares[$id] = $total ? intdiv($credited * $seconds, $total) : 0;
            $fractions[$id] = $total ? ($credited * $seconds) % $total : 0; $assigned += $shares[$id];
        }
        arsort($fractions, SORT_NUMERIC);
        foreach ($fractions as $id => $fraction) { if ($assigned >= $credited) break; $shares[$id]++; $assigned++; }
        foreach ($works as $work) {
            $work->seconds += $shares[$work->id];
            if (!$work->save(false)) throw new \RuntimeException('Work checkpoint failed.');
        }
        $build->worked_seconds += $credited; $build->revision++;
        if (!$build->save(false)) throw new \RuntimeException('Build progress failed.');
    }
}
