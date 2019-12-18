<?php

namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\HistoryRating;
use Yii;
use yii\rest\Controller;

class RatingController extends Controller
{
    public $rating = 0.01;

    public function behaviors(): array
    {
        $parent = parent::behaviors();
        $arr = [
            'authenticator' => [
                'class' => Auth::class,
            ],
        ];

        return array_merge($parent, $arr);
    }

    public function actionEveryday()
    {
        $beginOfDay = strtotime("midnight", time());
        $user = Yii::$app->user->identity;

        $todayRating = HistoryRating::find()
            ->where(['user_id' => $user->id])
            ->andWhere(['>=', 'created_at', $beginOfDay])
            ->one();

        if ($todayRating) {
            return ['message' => 'Рейтинг уже был обновлен сегодня'];
        }

        $userHistory = new HistoryRating();
        $userHistory->user_id = $user->id;
        $userHistory->rating = $this->rating;
        $userHistory->type = 'everyday';
        $userHistory->comment = 'everyday';
        $userHistory->save();

        return $user->person->updateCounters(['rating' => $this->rating]);
    }
}
