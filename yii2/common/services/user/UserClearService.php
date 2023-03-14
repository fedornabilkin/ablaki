<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:31
 */

namespace common\services\user;

use common\models\history\HistoryBalance;
use common\models\user\Person;
use common\models\user\User;
use DateTimeImmutable;
use Exception;
use Throwable;
use Yii;
use yii\db\ActiveQuery;
use yii\db\StaleObjectException;

class UserClearService
{
    /**
     * удалить - юзеры (боты) без рейтинга через три дня после регистрации
     * Чистить историю - юзеры через три месяца без посещений
     * - история рейтинга
     * - история баланса
     * - история выплат и пополнений
     * Удалить - юзеры через пол года без:
     * - подтвержденного мыла
     * - игр, форума, истории, вики, биржи
     * -
     * @return void
     */
    public function clear(): void
    {
        $users = $this->clearQuery()->orderBy(['id' => SORT_DESC])->limit(500)->all();

        $log['ids'] = [];
        $minCreatedAt = 0;
        /** @var User $user */
        foreach ($users as $user) {
            $log['ids'][] = $user->id;
            $log['username'][] = $user->username;
            $minCreatedAt = $minCreatedAt > $user->created_at ? $user->created_at : $minCreatedAt;
        }

        $log['count'] = count($log['ids']);

        // проверить наличие ограничений на удаление
        $restrictIds = $this->clearRestrictIds($minCreatedAt, ...$log['ids']);
        $log['ids'] = array_diff($log['ids'], $restrictIds);
        $log['restrict'] = $restrictIds;

        Yii::warning($log);

        /** @var User $clearUser */
        foreach ($users as $clearUser) {
            try {
                if (!in_array($clearUser->id, $restrictIds)) {
                    $this->removeUser($clearUser);
                } else {
                    // для restrict юзеров устанавливать флаг или тайм, чтобы не забирать их в выборку на следующий день
                    $this->lastCleaningUpdate($clearUser);
                }
            } catch (Exception $e) {
                Yii::error([$clearUser->id, $e]);
            }
        }
    }

    public function clearQuery(): ActiveQuery
    {
        $dt = new DateTimeImmutable('today');
        $threeDay = $dt->modify('-3 day');
        $ts = $threeDay->getTimestamp();

        $year2017 = new DateTimeImmutable('2017-01-01');
        $tsOld = $year2017->getTimestamp();

        return User::find()
            ->joinWith(['person' => static function (ActiveQuery $query) {
                return $query->noRating()->noRefovod()->noCleaning();
            }])
            ->where(['<', 'created_at', $ts])
            ->andWhere(['>', 'created_at', $tsOld])
            // условие для старой БД
            ->andWhere(['=', 'mail_approve', 0]);
    }

    public function clearRestrictIds(int $minTime, ...$ids): array
    {
        $restrict['history_balance'] = $this->restrictHistoryBalanceQuery($minTime, ...$ids)->all();
        $restrict['refovod'] = $this->restrictRefovodQuery($minTime, ...$ids)->all();

        $restrictIds = $log = [];
        foreach ($restrict as $type => $models) {
            $log[$type] = 0;
            foreach ($models as $model) {
                if (in_array($model->user_id, $restrictIds)) {
                    continue;
                }
                $log[$type]++;
                $restrictIds[] = $model->user_id;
            }
        }

        Yii::warning($log);

        return $restrictIds;
    }

    public function restrictHistoryBalanceQuery(int $minTime, ...$ids): ActiveQuery
    {
        return HistoryBalance::find()
            ->where(['in', 'user_id', $ids])
            ->andWhere(['>', 'created_at', $minTime]);
    }

    public function restrictRefovodQuery(int $minTime, ...$ids): ActiveQuery
    {
        return Person::find()
            ->where(['in', 'refovod', $ids])
            ->andWhere(['>', 'created_at', $minTime]);
    }

    /**
     * @param User $model
     * @return false|int
     * @throws Throwable
     * @throws StaleObjectException
     */
    public function removeUser(User $model)
    {
        return $model->delete();
    }

    public function lastCleaningUpdate(User $model): bool
    {
        return $model->person->setLastCleaning()->save();
    }
}
