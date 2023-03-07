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
use common\modules\games\models\GameDuel;
use common\modules\games\models\repo\Orel;
use common\modules\games\models\repo\Saper;
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
            ->orderBy(['id' => SORT_ASC])
            ->limit(50)
            ->all();

        $log['ids'] = [];
        foreach ($users as $user) {
            $log['ids'][] = $user->id;
            $log['username'][] = $user->username;
        }

        $log['count'] = count($log['ids']);

        // проверить наличие записей в историях и пр.
        $restrict['saper'] = Saper::find()->where(['in', 'user_id', $log['ids']])->all();
        $restrict['orel'] = Orel::find()->where(['in', 'user_id', $log['ids']])->all();
        $restrict['duel'] = GameDuel::find()->where(['in', 'user_id', $log['ids']])->all();
        $restrict['history_balance'] = HistoryBalance::find()->where(['in', 'user_id', $log['ids']])->all();
//        $restrict['forum_theme'] = ForumTheme::find()->where(['in', 'user_id', $log['ids']])->all();
//        $restrict['forum_comment'] = ForumComment::find()->where(['in', 'user_id', $log['ids']])->all();
//        $restrict['exchange'] = CreditExchange::find()->where(['in', 'user_id', $log['ids']])->all();

        foreach ($restrict as $index => $item) {
            if ($item) {
                $restrictIds = [];
                foreach ($item as $model) {
                    $restrictIds[] = $model->user_id;
                }
                $log['ids'] = array_diff($log['ids'], $restrictIds);
                $log['restrict'][$index] = $restrictIds;
            }
        }

        Yii::warning($restrict);
        Yii::warning($log);
    }
}
