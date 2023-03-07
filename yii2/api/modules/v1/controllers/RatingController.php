<?php

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\models\history\HistoryRating;
use Yii;
use yii\rest\Controller;

class RatingController extends Controller
{
    public $rating = 0.01;

    use AuthTrait;

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
        $userHistory->rating = $user->person->rating;
        $userHistory->rating_up = $this->rating;
        $userHistory->type = 'everyday';
        $userHistory->comment = 'everyday';
        $userHistory->save();

        return $user->person->updateCounters(['rating' => $this->rating]);
    }
}
