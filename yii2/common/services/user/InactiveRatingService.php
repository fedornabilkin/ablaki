<?php
namespace common\services\user;

use yii\db\Connection;
use yii\db\Query;

class InactiveRatingService
{
    const FIRST_DAYS = 180;
    const REPEAT_DAYS = 30;
    const MIN_RATING = 5;
    private $db;

    public function __construct(Connection $db) { $this->db = $db; }

    /** At most one 10% deduction per actual pass; no retroactive cascade on first run. */
    public function run(int $now = null): int
    {
        $now = $now ?? time();
        $count = 0; $cursor = 0;
        do {
            $ids = (new Query())->select('u.id')->from(['u' => '{{%user}}'])
                ->innerJoin(['p' => '{{%persone}}'], '[[p.user_id]] = [[u.id]]')
                ->where(['>', 'u.id', $cursor])->andWhere(['>', 'p.rating', self::MIN_RATING])
                ->andWhere(['<=', 'u.latest_activity', UserActivity::date($now - self::FIRST_DAYS * 86400)])
                ->andWhere(['or', ['u.inactive_rating_at' => null], ['<=', 'u.inactive_rating_at', UserActivity::date($now - self::REPEAT_DAYS * 86400)]])
                ->orderBy(['u.id' => SORT_ASC])->limit(500)->column($this->db);
            foreach ($ids as $id) { $cursor = (int)$id; if ($this->apply($cursor, $now)) $count++; }
        } while (count($ids) === 500);
        return $count;
    }

    public function apply(int $userId, int $now): bool
    {
        return $this->db->transaction(function () use ($userId, $now) {
            $locks = new CreditLedger($this->db);
            // Activity writes serialize on this same row before eligibility is checked again.
            $user = $locks->lock('{{%user}}', ['id' => $userId]);
            if (!$user) return false;
            $activity = UserActivity::timestamp($user['latest_activity']);
            $previous = UserActivity::timestamp($user['inactive_rating_at']);
            if ($activity === null || $activity > $now - self::FIRST_DAYS * 86400
                || ($previous !== null && $previous > $now - self::REPEAT_DAYS * 86400)) return false;
            $person = $locks->lock('{{%persone}}', ['user_id' => $userId]);
            if (!$person || !is_numeric($person['rating']) || !is_finite((float)$person['rating']) || $person['rating'] <= self::MIN_RATING) return false;
            $before = (float)$person['rating'];
            $after = max(self::MIN_RATING, round($before * .9, 2));
            if ($this->db->createCommand()->update('{{%persone}}', ['rating' => $after], ['id' => $person['id']])->execute() !== 1) throw new \RuntimeException('Rating write failed.');
            if ($this->db->createCommand()->insert('{{%history_rating}}', [
                'user_id' => $userId, 'rating' => $after, 'rating_up' => $after - $before,
                'type' => 'inactivity', 'created_at' => $now,
                'comment' => 'Неактивность с ' . $user['latest_activity'] . ': снижение рейтинга на 10%, минимум 5.',
            ])->execute() !== 1) throw new \RuntimeException('Rating history write failed.');
            if ($this->db->createCommand()->update('{{%user}}', ['inactive_rating_at' => UserActivity::date($now)], ['id' => $userId])->execute() !== 1) throw new \RuntimeException('Rating schedule write failed.');
            return true;
        });
    }
}
