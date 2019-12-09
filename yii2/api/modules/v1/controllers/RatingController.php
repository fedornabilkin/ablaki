<?php


namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\HistoryRating;
use common\models\user\Person;
use Yii;
use yii\rest\Controller;


class RatingController extends Controller
{

    public $rating = 0.01;


    public function behaviors()
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

        $timeZone = Yii::$app->getTimeZone();
        Yii::$app->setTimeZone('UTC');
        $beginOfDay = strtotime("midnight", time());
        $endOfDay = strtotime("tomorrow midnight", time()) - 1;
        Yii::$app->setTimeZone($timeZone);
        $user = Yii::$app->user;
        $todayRating = HistoryRating::find()
            ->where(['user_id' => $user->id])
            ->andWhere(['>=', 'created_at', $beginOfDay])
            ->andWhere(['<=', 'created_at', $endOfDay])
            ->one();
        if ($todayRating) {
            return 'Рейтинг пользователя сегодня уже был обновлен';
        }
//        if ($todayRating) {
//            return 'Рейтинг пользователя сегодня уже был обновлен (beginOfDay: ' . $beginOfDay  . ', еndOfDay: ' . $еndOfDay . ', created_at:' . $todayRating->created_at . ')';
//        }

        $person = Person::findOne(Yii::$app->user->identity->id);
        $userHistory = new HistoryRating();
        $userHistory->user_id = Yii::$app->user->identity->id;
        $userHistory->rating = $this->rating;
        $userHistory->type = 'everyday';
        $userHistory->comment = 'everyday';
        $userHistory->save();
        $upd = $person->updateCounters(['rating' => $this->rating]);
        return $upd;
    }




}