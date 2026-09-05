<?php

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\services\user\DailyRewardService;
use Yii;
use yii\rest\Controller;

class RatingController extends Controller
{
    use AuthTrait;

    public $rating = 0.01;

    public function actionEveryday()
    {
        $received = (new DailyRewardService(Yii::$app->db))
            ->claimRating((int)Yii::$app->user->id, (float)$this->rating);

        return $received
            ? true
            : ['message' => Yii::t('app', 'The rating has already been updated today')];
    }
}
