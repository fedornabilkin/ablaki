<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:31
 */

namespace common\services\user;

use common\models\history\HistoryBalance;
use common\models\user\User;
use DateTimeImmutable;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Expression;

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
        $dt = new DateTimeImmutable('today');
        $threeDay = $dt->modify('-3 day');
        $ts = $threeDay->getTimestamp();

        $year2017 = new DateTimeImmutable('2017-01-01');
        $tsOld = $year2017->getTimestamp();

        $users = User::find()
            ->joinWith(['person' => function (ActiveQuery $query) {
                return $query
                    ->andWhere(['<', 'rating', 0.01]);
            }])
            ->where(['<', 'created_at', $ts])
            ->andWhere(['>', 'created_at', $tsOld])
            ->andWhere(['=', 'mail_approve', 0]) // условие для старой БД
            ->andWhere([
                'or',
                ['<', 'last_login_at', 1],
                ['is', 'last_login_at', new Expression('null')]
            ])
            ->andWhere([
                'or',
                ['<', 'confirmed_at', 1],
                ['is', 'confirmed_at', new Expression('null')]
            ])
            ->orderBy(['id' => SORT_DESC])
            ->limit(100)
            ->all();

        $log['ids'] = [];
        $minCreatedAt = 0;
        /** @var User $user */
        foreach ($users as $user) {
            $log['ids'][] = $user->id;
            $log['username'][] = $user->username;
            $minCreatedAt = $minCreatedAt > $user->created_at ? $user->created_at : $minCreatedAt;
        }

        $log['count'] = count($log['ids']);

        // проверить наличие записей в историях и пр.
        $historyBalance = HistoryBalance::find()
            ->where(['in', 'user_id', $log['ids']])
            ->andWhere(['>', 'created_at', $minCreatedAt])
            ->all();

        if ($historyBalance) {
            $restrictIds = [];
            foreach ($historyBalance as $model) {
                if (in_array($model->user_id, $restrictIds)) {
                    continue;
                }
                $restrictIds[] = $model->user_id;
            }
            $log['ids'] = array_diff($log['ids'], $restrictIds);
            $log['restrict'] = $restrictIds;
        }

        // для restrict юзеров устанавливать флаг или тайм, чтобы не забирать их в выборку на следующий день

        Yii::warning($log);
    }
}
