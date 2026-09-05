<?php

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\helpers\UserHelper;
use common\models\history\HistoryRating;
use common\models\user\Person;
use common\models\user\User;
use common\modules\exchange\models\CreditExchange;
use common\modules\forum\models\ForumComment;
use common\modules\forum\models\ForumTheme;
use common\modules\games\models\GameOrel;
use common\modules\games\models\GameSaper;
use Yii;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class StatController extends Controller
{
    use AuthTrait;

    const CACHE_KEY = 'api.v1.stat.index';
    const CACHE_KEY_TOP = 'api.v1.stat.top.';
    const CACHE_KEY_USER = 'api.v1.stat.user.';
    const CACHE_DURATION = 300;
    const TOP_RATING_LIMIT = 5;
    const TOP_USERS_LIMIT = 10;

    /** Период выборки топа в секундах, 0 = за всё время */
    const PERIODS = [
        'all' => 0,
        'day' => 86400,
        'week' => 604800,
        'month' => 2592000,
        'half-year' => 15724800,
    ];

    public function authExceptAction(): array
    {
        return ['index', 'top', 'user'];
    }

    public function actionIndex(): array
    {
        return Yii::$app->cache->getOrSet(self::CACHE_KEY, function () {
            return [
                'users' => (int)User::find()->count(),
                'games' => [
                    'orel' => (int)GameOrel::find()->notFree()->count(),
                    'saper' => (int)GameSaper::find()->notFree()->count(),
                ],
                'forum' => [
                    'themes' => (int)ForumTheme::find()->count(),
                    'comments' => (int)ForumComment::find()->count(),
                ],
                'exchange' => (int)CreditExchange::find()->notFree()->count(),
                'topRating' => $this->getTopRating(),
            ];
        }, self::CACHE_DURATION);
    }

    /**
     * Топ пользователей за период. За всё время — по текущему рейтингу,
     * за период — по сумме прироста рейтинга в history_rating.
     */
    public function actionTop(string $period = 'all'): array
    {
        if (!array_key_exists($period, self::PERIODS)) {
            throw new BadRequestHttpException('Unknown period: ' . $period);
        }

        return Yii::$app->cache->getOrSet(self::CACHE_KEY_TOP . $period, function () use ($period) {
            $duration = self::PERIODS[$period];
            $list = $duration === 0
                ? $this->getTopRating(self::TOP_USERS_LIMIT)
                : $this->getTopGained($duration);

            return [
                'period' => $period,
                'list' => $list,
            ];
        }, self::CACHE_DURATION);
    }

    /**
     * Статистика активности пользователя для его стены. Только чтение.
     */
    public function actionUser(string $login): array
    {
        $user = User::find()->where(['username' => $login])->one();
        if ($user === null) {
            throw new NotFoundHttpException('User not found.');
        }

        return Yii::$app->cache->getOrSet(self::CACHE_KEY_USER . $user->id, function () use ($user) {
            $userId = (int)$user->id;

            return [
                'games' => [
                    'orel' => [
                        'played' => $this->countOrelPlayed($userId),
                        'won' => $this->countOrelWon($userId),
                    ],
                    'saper' => [
                        'played' => $this->countSaperPlayed($userId),
                    ],
                ],
                'forum' => [
                    'themes' => (int)ForumTheme::find()->where(['user_id' => $userId])->count(),
                    'comments' => (int)ForumComment::find()->where(['user_id' => $userId])->count(),
                ],
                'exchange' => (int)CreditExchange::find()
                    ->notFree()
                    ->andWhere(['or', ['user_id' => $userId], ['user_buyer' => $userId]])
                    ->count(),
            ];
        }, self::CACHE_DURATION);
    }

    private function countOrelPlayed(int $userId): int
    {
        return (int)GameOrel::find()
            ->notFree()
            ->andWhere(['or', ['user_id' => $userId], ['user_gamer' => $userId]])
            ->count();
    }

    private function countOrelWon(int $userId): int
    {
        // соперник побеждает, когда угадал (type = hod), создатель — когда нет
        return (int)GameOrel::find()
            ->notFree()
            ->andWhere([
                'or',
                ['and', ['user_id' => $userId], 'type != hod'],
                ['and', ['user_gamer' => $userId], 'type = hod'],
            ])
            ->count();
    }

    private function countSaperPlayed(int $userId): int
    {
        return (int)GameSaper::find()
            ->notFree()
            ->andWhere(['or', ['user_id' => $userId], ['user_gamer' => $userId]])
            ->count();
    }

    private function getTopRating(int $limit = self::TOP_RATING_LIMIT): array
    {
        $persons = Person::find()
            ->with(['user'])
            ->andWhere(['>', 'rating', 0])
            ->orderBy(['rating' => SORT_DESC])
            ->limit($limit)
            ->all();

        $list = [];
        foreach ($persons as $person) {
            if ($person->user === null) {
                continue;
            }
            $list[] = [
                'username' => $person->user->username,
                'rating' => UserHelper::ratingRound($person->rating),
            ];
        }

        return $list;
    }

    private function getTopGained(int $duration): array
    {
        $rows = HistoryRating::find()
            ->select(['user_id', 'rating_sum' => 'SUM(rating_up)'])
            ->andWhere(['>', 'created_at', time() - $duration])
            ->andWhere(['>', 'rating_up', 0])
            ->groupBy(['user_id'])
            ->orderBy(['rating_sum' => SORT_DESC])
            ->limit(self::TOP_USERS_LIMIT)
            ->asArray()
            ->all();

        $users = User::find()
            ->where(['id' => array_column($rows, 'user_id')])
            ->indexBy('id')
            ->all();

        $list = [];
        foreach ($rows as $row) {
            if (!isset($users[$row['user_id']])) {
                continue;
            }
            $list[] = [
                'username' => $users[$row['user_id']]->username,
                'rating' => round((float)$row['rating_sum'], 2),
            ];
        }

        return $list;
    }
}
