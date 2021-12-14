<?php

namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\history\HistoryRating;
use Yii;
use yii\rest\Controller;

class RatingController extends Controller
{
    public $rating = 0.01;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'authenticator' => [
                'class' => Auth::class,
            ],
        ]);
    }

    public function actionEveryday()
    {
        $beginOfDay = strtotime("midnight", time());
        $user = Yii::$app->user->identity;

        $todayRating = HistoryRating::find()
            ->where(['user_id' => $user->id])
            ->andWhere(['>=', 'created_at', $beginOfDay])
            ->andWhere(['=', 'type', 'everyday'])
            ->one();

        if ($todayRating) {
            return ['message' => 'The rating has already been updated today'];
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
